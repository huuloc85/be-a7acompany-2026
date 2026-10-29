<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Báo cáo sản lượng CAP7A</title>
        <style>
            @media only screen and (max-width: 640px) {
                .camera-column {
                    display: block !important;
                    width: 100% !important;
                    box-sizing: border-box !important;
                    padding: 12px !important;
                }
            }
        </style>
    </head>

    <body style="margin: 0; padding: 0; background: #f3f5f8; color: #172b4d; font-family: Arial, Helvetica, sans-serif">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td align="center" style="padding: 12px">
                    <table
                        role="presentation"
                        width="100%"
                        cellpadding="0"
                        cellspacing="0"
                        style="max-width: 880px; background: #ffffff; border: 1px solid #dfe5ec; border-radius: 12px">
                        <tr>
                            <td style="padding: 16px 20px; border-bottom: 3px solid #245c75">
                                <p
                                    style="
                                        margin: 0 0 6px;
                                        font-size: 12px;
                                        font-weight: bold;
                                        letter-spacing: 2px;
                                        color: #245c75;
                                    ">
                                    CAP7A · BÁO CÁO NGÀY
                                </p>
                                <h1 style="margin: 0; font-size: 22px; line-height: 1.3">Sản lượng kiểm tra</h1>
                                <p style="margin: 6px 0 0; font-size: 12px; color: #526176">
                                    {{ $generatedAt->format('d/m/Y · H:i:s') }}
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <table
                                    role="presentation"
                                    width="100%"
                                    cellpadding="0"
                                    cellspacing="0"
                                    style="table-layout: fixed">
                                    <tr>
                                        @foreach ($cameras as $camera)
                                            @php($daily = $camera['daily'])
                                            <td
                                                class="camera-column"
                                                width="50%"
                                                valign="top"
                                                style="padding: 16px; border-bottom: 1px solid #e5eaf0">
                                                <h2 style="margin: 0 0 12px; font-size: 16px; line-height: 1.5">
                                                    {{ $camera['label'] }}
                                                </h2>
                                                <table
                                                    width="100%"
                                                    cellpadding="0"
                                                    cellspacing="0"
                                                    style="
                                                        border-collapse: collapse;
                                                        table-layout: fixed;
                                                        width: 100%;
                                                        font-size: 14px;
                                                        line-height: 1.5;
                                                        overflow-wrap: anywhere;
                                                        word-wrap: break-word;
                                                    ">
                                                    <thead>
                                                        <tr style="background: #f3f5f8">
                                                            <th
                                                                scope="col"
                                                                align="left"
                                                                style="padding: 8px 10px; color: #526176">
                                                                Chỉ tiêu
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                align="right"
                                                                style="padding: 8px 10px; color: #526176">
                                                                Giá trị
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach (['ok' => 'OK', 'ng' => 'NG', 'total' => 'Tổng kiểm tra'] as $key => $label)
                                                            <tr>
                                                                <th
                                                                    scope="row"
                                                                    align="left"
                                                                    style="
                                                                        padding: 8px 10px;
                                                                        border-bottom: 1px solid #e5eaf0;
                                                                        font-weight: {{ $key === 'total' ? 'bold' : 'normal' }};
                                                                    ">
                                                                    {{ $label }}
                                                                </th>
                                                                <td
                                                                    align="right"
                                                                    style="
                                                                        padding: 8px 10px;
                                                                        border-bottom: 1px solid #e5eaf0;
                                                                        font-weight: bold;
                                                                        font-variant-numeric: tabular-nums;
                                                                    ">
                                                                    {{ number_format($daily['report'][$key], 0, ',', '.') }}
                                                                </td>
                                                            </tr>
                                                        @endforeach

                                                        @foreach ($camera['details'] as $label => $value)
                                                            <tr>
                                                                <th
                                                                    scope="row"
                                                                    align="left"
                                                                    style="
                                                                        padding: 6px 10px;
                                                                        border-bottom: 1px solid #e5eaf0;
                                                                        font-weight: normal;
                                                                    ">
                                                                    {{ $label }}
                                                                </th>
                                                                <td
                                                                    align="right"
                                                                    style="
                                                                        padding: 6px 10px;
                                                                        border-bottom: 1px solid #e5eaf0;
                                                                        font-weight: bold;
                                                                    ">
                                                                    {{ $value }}
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                                <p
                                                    style="
                                                        margin: 8px 0 0;
                                                        font-size: 12px;
                                                        line-height: 1.6;
                                                        color: #526176;
                                                    ">
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
                                                        Lưu ý: Số liệu có thể chưa đầy đủ hoặc đã được đặt lại trong
                                                        ngày.
                                                    </p>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
