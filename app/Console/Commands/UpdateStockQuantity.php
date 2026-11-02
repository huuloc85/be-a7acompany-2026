<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TotalMonthQuantity;
use App\Services\ProductInventoryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateStockQuantity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update-stock-quantity:cron
        {--from= : Tháng bắt đầu tính lại (MM-YYYY), giữ nguyên tồn đầu tháng này}
        {--product= : Chỉ tính lại sản phẩm có ID này}
        {--dry-run : Xem thay đổi, không ghi dữ liệu}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update stock quantity monthly';

    /**
     * Execute the console command.
     */
    public function handle(ProductInventoryService $inventory): int
    {
        $from = $this->option('from') ?: Carbon::now()->startOfMonth()->subMonth()->format('m-Y');
        if (! preg_match('/^(0[1-9]|1[0-2])-\d{4}$/', $from)
            || Carbon::createFromFormat('!m-Y', $from)->gte(Carbon::now()->startOfMonth())) {
            $this->error('Tháng bắt đầu phải có dạng MM-YYYY và trước tháng hiện tại.');

            return self::FAILURE;
        }
        if ($this->option('product') !== null && ! ctype_digit((string) $this->option('product'))) {
            $this->error('ID sản phẩm phải là số nguyên.');

            return self::FAILURE;
        }

        try {
            $changes = DB::transaction(function () use ($inventory, $from) {
                $changes = [];
                $products = Product::query()->when($this->option('product') !== null,
                    fn ($query) => $query->whereKey($this->option('product'))
                )->orderBy('id')->get();

                foreach ($products as $product) {
                    array_push($changes, ...$inventory->recalculate($product->id, $from, (bool) $this->option('dry-run')));
                    $month = Carbon::now()->format('m-Y');
                    if (! TotalMonthQuantity::where('product_id', $product->id)->where('month', $month)->where('status', 7)->exists()) {
                        $moq = TotalMonthQuantity::where('product_id', $product->id)->where('status', 7)->latest()->value('totalQuan');
                        $changes[] = [$product->id, $month, 7, null, $moq];
                        if (! $this->option('dry-run')) {
                            TotalMonthQuantity::create([
                                'product_id' => $product->id, 'month' => $month,
                                'status' => 7, 'totalQuan' => $moq,
                            ]);
                        }
                    }
                }

                return $changes;
            });
            $this->table(['ID sản phẩm', 'Tháng', 'Trạng thái', 'Số cũ', 'Số mới'], $changes);
            $message = ($this->option('dry-run') ? 'Dự kiến cập nhật ' : 'Đã cập nhật ').count($changes).' bản ghi.';
            $this->info($message);
            if (! $this->option('dry-run')) {
                Log::info($message, ['from' => $from, 'product' => $this->option('product')]);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Không thể cập nhật tồn đầu kỳ.', ['exception' => $e]);
            $this->error('Không thể cập nhật tồn đầu kỳ. Vui lòng kiểm tra nhật ký hệ thống.');

            return self::FAILURE;
        }
    }
}
