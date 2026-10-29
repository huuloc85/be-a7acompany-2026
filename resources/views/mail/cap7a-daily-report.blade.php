<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Báo cáo sản lượng CAP7A</title>
    </head>

    <body style="margin: 0; padding: 0; background: #f3f5f8; color: #172b4d; font-family: Arial, Helvetica, sans-serif">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td align="center" style="padding: 24px 12px">
                    <table
                        role="presentation"
                        width="100%"
                        cellpadding="0"
                        cellspacing="0"
                        style="max-width: 640px; background: #ffffff; border: 1px solid #dfe5ec; border-radius: 12px">
                        <tr>
                            <td style="padding: 28px 24px; border-bottom: 3px solid #245c75">
                                <p
                                    style="
                                        margin: 0 0 10px;
                                        font-size: 12px;
                                        font-weight: bold;
                                        letter-spacing: 2px;
                                        color: #245c75;
                                    ">
                                    CAP7A · BÁO CÁO NGÀY
                                </p>
                                <h1 style="margin: 0; font-size: 25px; line-height: 1.3">Sản lượng kiểm tra</h1>
                            </td>
                        </tr>
                        @foreach ($cameras as $camera)
                            @php($daily = $camera['daily'])
                            <tr>
                                <td style="padding: 24px; border-bottom: 1px solid #e5eaf0">
                                    <h2 style="margin: 0 0 16px; font-size: 18px; line-height: 1.5">
                                        {{ $camera['label'] }}
                                    </h2>
                                    <table
                                        width="100%"
                                        cellpadding="0"
                                        cellspacing="0"
                                        style="border-collapse: collapse; font-size: 15px">
                                        <thead>
                                            <tr style="background: #f3f5f8">
                                                <th scope="col" align="left" style="padding: 10px 12px; color: #526176">
                                                    Kết quả
                                                </th>
                                                <th
                                                    scope="col"
                                                    align="right"
                                                    style="padding: 10px 12px; color: #526176">
                                                    Số lượng
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach (['ok' => 'OK', 'ng' => 'NG', 'unconfirmed' => 'Chưa xác nhận', 'total' => 'Tổng kiểm tra'] as $key => $label)
                                                <tr>
                                                    <th
                                                        scope="row"
                                                        align="left"
                                                        style="
                                                            padding: 12px;
                                                            border-bottom: 1px solid #e5eaf0;
                                                            font-weight: {{ $key === 'total' ? 'bold' : 'normal' }};
                                                        ">
                                                        {{ $label }}
                                                    </th>
                                                    <td
                                                        align="right"
                                                        style="
                                                            padding: 12px;
                                                            border-bottom: 1px solid #e5eaf0;
                                                            font-weight: bold;
                                                            font-variant-numeric: tabular-nums;
                                                        ">
                                                        {{ number_format($daily['report'][$key], 0, ',', '.') }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    <p style="margin: 12px 0 0; font-size: 12px; line-height: 1.6; color: #526176">
                                        Cập nhật:
                                        {{ $daily['received_at_ms'] ? \Carbon\CarbonImmutable::createFromTimestampMs($daily['received_at_ms'])->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') : 'Chưa có thời điểm cập nhật' }}
                                    </p>
                                    @if ($daily['reset_at_ms'] > 0 || $daily['report']['partial'] || $daily['report']['warning'])
                                        <p
                                            style="
                                                margin: 12px 0 0;
                                                padding: 12px;
                                                background: #fff7e6;
                                                color: #784a00;
                                                font-size: 13px;
                                                line-height: 1.6;
                                            ">
                                            Lưu ý: Số liệu có thể chưa đầy đủ hoặc đã được đặt lại trong ngày.
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        <tr>
                            <td style="padding: 20px 24px; font-size: 12px; line-height: 1.7; color: #526176">
                                Số liệu theo lần cập nhật gần nhất của từng camera trong ngày. Báo cáo riêng theo công
                                đoạn, không cộng gộp giữa các camera.
                                <br />
                                Thời gian hiển thị theo giờ Việt Nam (UTC+7).
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
