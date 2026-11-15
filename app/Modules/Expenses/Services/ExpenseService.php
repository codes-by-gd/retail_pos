<?php

namespace App\Modules\Expenses\Services;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Records shop expenses. Cash can come out of an open register drawer (it then reduces the shift's
 * expected cash); such an expense is locked once that shift is closed, so Z-reports never change.
 */
class ExpenseService
{
    public const DISK = 'local';

    public function __construct(private DocumentNumberService $numbers, private RegisterService $registers) {}

    public function create(array $data, Branch $branch, User $user, ?UploadedFile $file = null): Expense
    {
        return DB::transaction(function () use ($data, $branch, $user, $file) {
            $session = $this->drawer($data, $branch, null);

            $expense = Expense::create([
                ...$this->fields($data),
                'reference_no' => $this->numbers->next('EXP', $branch, Carbon::parse($data['date'])),
                'branch_id' => $branch->id,
                'register_session_id' => $session?->id,
                'user_id' => $user->id,
            ]);
            if ($file) {
                $expense->update(['attachment_path' => $file->store("expenses/{$branch->id}", self::DISK)]);
            }

            return $expense;
        });
    }

    public function update(Expense $expense, array $data, User $user, ?UploadedFile $file = null, bool $removeFile = false): Expense
    {
        return DB::transaction(function () use ($expense, $data, $file, $removeFile) {
            $this->ensureEditable($expense);
            $session = $this->drawer($data, $expense->branch, $expense);

            $expense->fill([...$this->fields($data), 'register_session_id' => $session?->id]);
            if ($file || $removeFile) {
                $this->deleteFile($expense);
                $expense->attachment_path = $file?->store("expenses/{$expense->branch_id}", self::DISK);
            }
            $expense->save();

            return $expense;
        });
    }

    public function delete(Expense $expense, User $user): void
    {
        $this->ensureEditable($expense);
        $this->deleteFile($expense);
        activity()->causedBy($user)->withProperties(['reference_no' => $expense->reference_no, 'amount' => (string) $expense->amount])->log('Deleted expense');
        $expense->delete();
    }

    /** Why the expense can't be changed, or null. */
    public function lockReason(Expense $expense): ?string
    {
        if ($expense->register_session_id && $expense->session?->status === RegisterSession::CLOSED) {
            return 'Paid from a register shift that is already closed — the Z-report can no longer change.';
        }

        return null;
    }

    private function ensureEditable(Expense $expense): void
    {
        $expense->loadMissing('session');
        if ($reason = $this->lockReason($expense)) {
            throw new \DomainException($reason);
        }
    }

    private function fields(array $data): array
    {
        return [
            'expense_category_id' => $data['expense_category_id'],
            'date' => $data['date'],
            'amount' => (string) BigDecimal::of((string) $data['amount'])->toScale(2),
            'gst_amount' => (string) BigDecimal::of((string) ($data['gst_amount'] ?? 0))->toScale(2),
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_to' => $data['paid_to'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }

    /** The open drawer the cash comes from (cash only), checked for branch and enough cash. */
    private function drawer(array $data, Branch $branch, ?Expense $existing): ?RegisterSession
    {
        if (($data['method'] ?? null) !== 'cash' || empty($data['register_session_id'])) {
            return null;
        }
        $session = RegisterSession::whereKey($data['register_session_id'])->lockForUpdate()->first();
        if (! $session || $session->branch_id !== $branch->id || $session->status !== RegisterSession::OPEN) {
            throw new \DomainException('Choose an open register of this branch, or record the cash as paid from outside the drawer.');
        }
        // cash available = expected now (+ this expense's own amount when it already came from this drawer)
        $available = BigDecimal::of($this->registers->summary($session)['expected_cash']);
        if ($existing && $existing->register_session_id === $session->id) {
            $available = $available->plus($existing->getOriginal('amount'));
        }
        if (BigDecimal::of((string) $data['amount'])->isGreaterThan($available)) {
            throw new \DomainException("Only ₹{$available} cash is in that drawer.");
        }

        return $session;
    }

    private function deleteFile(Expense $expense): void
    {
        if ($expense->attachment_path) {
            Storage::disk(self::DISK)->delete($expense->attachment_path);
        }
    }
}
