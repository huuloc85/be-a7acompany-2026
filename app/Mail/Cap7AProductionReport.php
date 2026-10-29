<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

class Cap7AProductionReport extends Mailable
{
    public function __construct(public string $system, public array $state) {}

    public function build(): self
    {
        $date = CarbonImmutable::createFromTimestampMs($this->state['app_exited_at_ms'])
            ->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y');

        return $this->subject('Báo cáo sản lượng CAP7A '.strtoupper($this->system).' · '.$date)
            ->text('mail.cap7a-production-report');
    }
}
