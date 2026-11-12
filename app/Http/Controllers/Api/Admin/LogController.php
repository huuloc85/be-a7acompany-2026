<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Log;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SplFileObject;
use Throwable;

class LogController extends BaseController
{
    // Lấy danh sách log
    public function index()
    {
        try {
            $logs = Log::orderBy('created_at', 'DESC')->get();

            return response()->json([
                'success' => true,
                'data' => $logs,
                'total' => count($logs),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi khi lấy danh sách log',
            ], 500);
        }
    }

    // Xóa một log theo ID
    public function delete($id)
    {
        $log = Log::find($id);

        if (! $log) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy log!',
            ]);
        }

        try {
            $log->delete();

            return response()->json([
                'success' => true,
                'message' => 'Xóa log thành công!',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Xóa log không thành công!',
            ], 500);
        }
    }

    // Xóa tất cả log
    public function deleteAll()
    {
        try {
            Log::truncate();

            return response()->json([
                'success' => true,
                'message' => 'Xóa tất cả log thành công!',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Xóa tất cả log không thành công!',
            ], 500);
        }
    }

    public function getSystemLogs()
    {
        try {
            $paths = $this->systemLogPaths();
            if ($paths === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Log Kỹ Thuật chưa được cấu hình ghi vào file.',
                ], 503);
            }

            usort($paths, fn (string $a, string $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));

            $remaining = 1000;
            $content = '';
            foreach ($paths as $path) {
                $lines = $this->readSystemLogTail($path, $remaining);
                if ($lines === []) {
                    continue;
                }

                $tail = implode('', $lines);
                if ($content !== '' && ! str_ends_with($tail, "\n")) {
                    $tail .= "\n";
                }
                $content = $tail.$content;
                $remaining -= count($lines);
                if ($remaining === 0) {
                    break;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $content,
            ], options: JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'success' => false,
                'message' => 'Không thể đọc file Log Kỹ Thuật.',
            ], 500);
        }
    }

    public function clearSystemLogs()
    {
        try {
            $paths = $this->systemLogPaths();
            if ($paths === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Log Kỹ Thuật chưa được cấu hình ghi vào file.',
                ], 503);
            }

            foreach ($paths as $path) {
                if (File::put($path, '', true) === false) {
                    throw new RuntimeException('Cannot clear Laravel log file.');
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã làm sạch Log Kỹ Thuật.',
            ]);
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'success' => false,
                'message' => 'Không thể làm sạch Log Kỹ Thuật.',
            ], 500);
        }
    }

    private function systemLogPaths(): ?array
    {
        $channels = [config('logging.default')];
        $configuredChannels = config('logging.channels');
        $visited = [];
        $usesFiles = false;
        $paths = [];

        while ($channels !== []) {
            $channel = array_pop($channels);
            if (isset($visited[$channel])) {
                continue;
            }
            $visited[$channel] = true;
            $config = $configuredChannels[$channel] ?? [];

            switch ($config['driver'] ?? null) {
                case 'stack':
                    $children = $config['channels'];
                    array_push($channels, ...(is_array($children) ? $children : explode(',', $children)));
                    break;
                case 'single':
                    $usesFiles = true;
                    if (is_file($config['path'])) {
                        $paths[] = $config['path'];
                    }
                    break;
                case 'daily':
                    $usesFiles = true;
                    $file = pathinfo($config['path']);
                    // Laravel's daily driver uses Monolog's default YYYY-MM-DD suffix.
                    $date = '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]';
                    $extension = isset($file['extension']) ? '.'.$file['extension'] : '';
                    $matches = File::glob($file['dirname'].'/'.$file['filename'].'-'.$date.$extension, GLOB_ERR);
                    if ($matches === false) {
                        throw new RuntimeException('Cannot list Laravel log files.');
                    }
                    array_push($paths, ...array_filter($matches, 'is_file'));
                    break;
            }
        }

        return $usesFiles ? array_unique($paths) : null;
    }

    private function readSystemLogTail(string $path, int $limit): array
    {
        $file = new SplFileObject($path, 'rb');
        if (! $file->flock(LOCK_SH) || $file->fseek(0, SEEK_END) !== 0) {
            throw new RuntimeException('Cannot read Laravel log file.');
        }

        $position = $file->ftell();
        $chunks = [];
        $newlines = 0;
        // Read one extra delimiter so the tail starts at a complete line.
        while ($position > 0 && $newlines <= $limit) {
            $length = min(8192, $position);
            $position -= $length;
            if ($file->fseek($position) !== 0 || ($chunk = $file->fread($length)) === false) {
                throw new RuntimeException('Cannot read Laravel log file.');
            }
            $chunks[] = $chunk;
            $newlines += substr_count($chunk, "\n");
        }

        $buffer = implode('', array_reverse($chunks));

        return $buffer === '' ? [] : array_slice(preg_split('/(?<=\n)(?!\z)/', $buffer), -$limit);
    }
}
