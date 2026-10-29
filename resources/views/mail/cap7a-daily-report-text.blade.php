BÁO CÁO SẢN LƯỢNG CAP7A Ngày: {{ $generatedAt->format('d/m/Y') }} Lập lúc: {{ $generatedAt->format('H:i:s') }} (giờ
Việt Nam)

@foreach ($cameras as $camera)
    {{ $camera['label'] }}
    @foreach (['ok' => 'OK', 'ng' => 'NG', 'total' => 'Tổng kiểm tra'] as $key => $label)
            {{ $label }}: {{ number_format($camera['daily']['report'][$key], 0, ',', '.') }}
    @endforeach

    Cập nhật:
    {{ $camera['daily']['received_at_ms'] ? \Carbon\CarbonImmutable::createFromTimestampMs($camera['daily']['received_at_ms'])->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') : 'Chưa có thời điểm cập nhật' }}
    @if ($camera['daily']['reset_at_ms'] > 0 || $camera['daily']['report']['partial'] || $camera['daily']['report']['warning'])
        Lưu ý: Số liệu có thể chưa đầy đủ hoặc đã được đặt lại trong ngày.
    @endif
@endforeach
