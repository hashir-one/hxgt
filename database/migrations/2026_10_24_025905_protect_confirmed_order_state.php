<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_paid_order() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'paid' AND (
        NEW.status IS DISTINCT FROM OLD.status OR
        NEW.payable_amount_minor IS DISTINCT FROM OLD.payable_amount_minor OR
        NEW.currency IS DISTINCT FROM OLD.currency OR
        NEW.merchant_id IS DISTINCT FROM OLD.merchant_id OR
        NEW.confirmation_method IS DISTINCT FROM OLD.confirmation_method OR
        NEW.paid_at IS DISTINCT FROM OLD.paid_at
    ) THEN RAISE EXCEPTION 'Confirmed payment is immutable; record refunds separately';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER orders_paid_immutable BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION protect_paid_order();
SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_paid_immutable BEFORE UPDATE ON orders
WHEN OLD.status = 'paid' AND (
    NEW.status IS NOT OLD.status OR NEW.payable_amount_minor IS NOT OLD.payable_amount_minor OR
    NEW.currency IS NOT OLD.currency OR NEW.merchant_id IS NOT OLD.merchant_id OR
    NEW.confirmation_method IS NOT OLD.confirmation_method OR NEW.paid_at IS NOT OLD.paid_at
)
BEGIN SELECT RAISE(ABORT, 'Confirmed payment is immutable; record refunds separately'); END;
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_paid_immutable ON orders');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_paid_order()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_paid_immutable');
        }
    }
};
