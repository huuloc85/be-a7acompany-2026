<?php

namespace App\Console\Commands;

use App\Models\RequestForm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileRequestFormApprovals extends Command
{
    protected $signature = 'request-form:reconcile-approvals {--id=* : Limit to request IDs} {--apply : Apply only to explicitly selected IDs}';

    protected $description = 'Preview pending regular forms with complete approval signatures; optionally finalize selected IDs';

    public function handle(): int
    {
        $ids = $this->option('id');
        if ($this->option('apply') && ! $ids) {
            $this->error('Specify --id for every request to update. Without --apply this command is read-only.');

            return self::FAILURE;
        }
        $query = RequestForm::where('status', RequestForm::STATUS_PENDING)
            ->where('type', '!=', RequestForm::TYPE_GIAY_UY_QUYEN);
        if ($ids) {
            $query->whereIn('id', $ids);
        }
        foreach ($query->lazyById(100) as $form) {
            $approver = $form->type === RequestForm::TYPE_DON_XIN_TANG_CA ? $form->supervisor_approved_by : $form->manager_approved_by;
            if (! $form->hasRequiredApprovalSignatures() || ! $approver) {
                continue;
            }
            if (! $this->option('apply')) {
                $this->line("Eligible: #{$form->id}");

                continue;
            }
            $updated = DB::transaction(function () use ($form) {
                $current = RequestForm::whereKey($form->id)->lockForUpdate()->first();

                return $current && $current->finalizeApproval();
            });
            $this->line(($updated ? 'Updated: #' : 'Skipped: #').$form->id);
        }

        return self::SUCCESS;
    }
}
