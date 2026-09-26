<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Deliveries — a shop order in a courier's hands
|--------------------------------------------------------------------------
|
| One row per order handed to a rider or courier agent: who has it, when
| they picked it up, when it was delivered and to whom, with the proof they
| recorded on their phone. The order's own status moves with it (Shipped →
| Out for delivery → Delivered) through the same transitions the office
| uses, so the customer's tracking page and emails need nothing new.
|
| A delivery that could not be made is kept with its reason and the
| attempt count; the office reassigns or retries from the same row.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // assigned | picked_up | out_for_delivery | delivered | failed | cancelled
            $table->string('status', 24)->default('assigned')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            // Proof, recorded by the courier at the door.
            $table->string('recipient_name', 120)->nullable();
            $table->text('proof_note')->nullable();
            $table->string('proof_photo_path', 255)->nullable();
            $table->decimal('proof_lat', 10, 7)->nullable();
            $table->decimal('proof_lng', 10, 7)->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('office_notes')->nullable();
            $table->timestamps();

            $table->index(['courier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
