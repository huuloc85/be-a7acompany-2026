@php
    $time = fn ($value) => \Carbon\CarbonImmutable::createFromTimestampMs($value)->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y');
    $number = fn ($value) => number_format($value, 0, ',', '.');
    $classified = $state['ok'] + $state['ng'];
    $percent = fn ($value) => $classified > 0 ? number_format(100 * $value / $classified, 2, ',', '.').'%' : '—';
    $duration = function ($seconds) {
        if (! is_numeric($seconds) || $seconds < 0) {
            return 'Chưa ghi nhận';
        }
        $seconds = (int) $seconds;

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    };
@endphp

BÁO CÁO SẢN LƯỢNG CAP7A {{ strtoupper($system) }} Mở ứng dụng: {{ $time($state['app_started_at_ms']) }} Thoát ứng
dụng: {{ $time($state['app_exited_at_ms']) }} Tổng sản phẩm: {{ $number($state['total']) }} OK:
{{ $number($state['ok']) }} NG: {{ $number($state['ng']) }} Tỷ lệ OK: {{ $percent($state['ok']) }} Tỷ lệ NG:
{{ $percent($state['ng']) }} Thời gian chạy trong ngày: {{ $duration($state['run_seconds'] ?? null) }} Thời gian dừng
trong ngày: {{ $duration($state['stop_seconds'] ?? null) }} Số liệu ghi nhận tại thời điểm thoát ứng dụng. Thời gian
chạy/dừng là số liệu trong ngày {{ $state['runtime_date'] ?? 'chưa ghi nhận' }}, không phải thời lượng của riêng phiên
mở ứng dụng.
