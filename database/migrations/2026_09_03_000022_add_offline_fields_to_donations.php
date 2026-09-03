<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline gifts — cash at an event, a cheque, a bank transfer.
 *
 * Deliberately NOT a separate table. An offline gift is a gift: it belongs in
 * the same ledger, counts towards the same cause total, and gets the same
 * acknowledgement with a number from the same series. A parallel table would
 * mean every report had to remember to union two sources, and the one that
 * forgot would be the one shown to a trustee.
 *
 * What it needs beyond an online gift is the evidence: which physical
 * instrument, what its number was, and who at the foundation entered it.
 * `recorded_by` already exists on donations; these are the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            // cash | cheque | bank_transfer | in_kind | other
            $table->string('offline_method', 32)->nullable()->after('momo_network');

            /*
             * The cheque number, the deposit slip reference, the bank transfer
             * narration. What Finance matches against the bank statement — the
             * offline equivalent of a Paystack reference.
             */
            $table->string('offline_reference', 191)->nullable()->after('offline_method');

            // When the money actually arrived, which is not when somebody got
            // round to entering it.
            $table->date('received_on')->nullable()->after('offline_reference');

            $table->index('offline_reference');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['offline_reference']);
            $table->dropColumn(['offline_method', 'offline_reference', 'received_on']);
        });
    }
};
