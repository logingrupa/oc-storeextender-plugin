<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class CreateTableOrderAttributions extends Migration
{
    public const TABLE = 'logingrupa_storeextender_order_attributions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable): void {
            $obTable->unsignedBigInteger('order_id')->primary();
            $obTable->string('order_channel', 32)->index('idx_se_order_attr_channel');
            $obTable->string('agent_client', 40)->nullable()->index('idx_se_order_attr_client');
            $obTable->boolean('verified')->default(false);
            $obTable->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
