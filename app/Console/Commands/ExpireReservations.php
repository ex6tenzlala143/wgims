<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature   = 'reservations:expire';
    protected $description = 'Expire reservations whose expires_at date has passed and that still have unreleased quantity.';

    public function handle(): int
    {
        $expired = Reservation::whereIn('status', Reservation::ACTIVE_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $reservation) {
            $reservation->expire();
            $count++;
        }

        $this->info("Expired {$count} reservation(s).");
        return self::SUCCESS;
    }
}
