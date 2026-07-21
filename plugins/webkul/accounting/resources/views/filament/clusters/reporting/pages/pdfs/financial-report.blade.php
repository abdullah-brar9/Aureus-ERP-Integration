<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $template->name }} — {{ $year }}</title>
    <style>
        @page {
            margin: 90px 36px 60px 36px;
        }

        * {
            font-family: DejaVu Sans, sans-serif;
            box-sizing: border-box;
        }

        body {
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }

        .pdf-header {
            position: fixed;
            top: -70px;
            left: 0;
            right: 0;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 6px;
        }

        .pdf-header .company {
            font-size: 10px;
            color: #6b7280;
        }

        .pdf-header .title {
            font-size: 15px;
            font-weight: bold;
            margin: 2px 0;
        }

        .pdf-header .meta {
            font-size: 8px;
            color: #6b7280;
        }

        .pdf-footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            font-size: 8px;
            color: #6b7280;
            border-top: 1px solid #d1d5db;
            padding-top: 4px;
        }

        .pdf-footer .page-number:after {
            content: "Page " counter(page);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 2.5px 5px;
            vertical-align: bottom;
        }

        thead th {
            border-bottom: 1px solid #1f2937;
            font-size: 8.5px;
        }

        .caption {
            text-align: left;
            white-space: nowrap;
        }

        .value {
            text-align: right;
            white-space: nowrap;
        }

        .spacer-col {
            width: 6px;
            background: #f3f4f6;
        }

        .bold {
            font-weight: bold;
        }

        .section {
            font-weight: bold;
        }

        .check-fail {
            color: #b91c1c;
            font-weight: bold;
        }

        .check-ok {
            color: #15803d;
        }

        .blank td {
            height: 8px;
        }
    </style>
</head>
<body>
    <div class="pdf-header">
        <div class="company">{{ $companyLabel }}</div>
        <div class="title">{{ $template->name }}</div>
        <div class="meta">
            {{ __('Period') }}: {{ $year }} &nbsp;•&nbsp;
            {{ __('Generated') }}: {{ $generatedAt->format('M d, Y H:i') }}
            @if ($template->status !== \Webkul\Accounting\Enums\TemplateStatus::PUBLISHED)
                &nbsp;•&nbsp; {{ strtoupper($template->statusEnum()->getLabel()) }}
            @endif
        </div>
    </div>

    <div class="pdf-footer">
        <span>{{ $template->name }} — {{ $year }}</span>
        <span style="float: right" class="page-number"></span>
    </div>

    <table>
        <thead>
            <tr>
                <th class="caption"></th>
                @foreach ($columns as $column)
                    @if ($column->isSpacer())
                        <th class="spacer-col"></th>
                    @else
                        <th class="value">
                            {{ $column->label }}<br>
                            <span style="font-weight: normal; color: #6b7280">{{ $subLabel($column) }}</span>
                        </th>
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @continue(! $row->isVisible)

                @if ($row->lineType === \Webkul\Accounting\Enums\LineType::SPACER)
                    <tr class="blank"><td colspan="{{ count($columns) + 1 }}"></td></tr>
                    @continue
                @endif

                <tr>
                    <td class="caption {{ $row->isBold ? 'bold' : '' }} {{ $row->lineType === \Webkul\Accounting\Enums\LineType::SECTION_HEADER ? 'section' : '' }}"
                        style="padding-left: {{ 5 + $row->indentLevel * 12 }}px">
                        {{ $row->caption }}
                    </td>
                    @foreach ($columns as $column)
                        @if ($column->isSpacer())
                            <td class="spacer-col"></td>
                        @else
                            @php
                                $value = $row->carriesValues() ? $row->valueFor($column->key) : null;
                                $checkFails = $row->isCheck && $value !== null && abs($value) > 0.01;
                            @endphp
                            <td class="value {{ $row->isBold ? 'bold' : '' }} {{ $row->isCheck ? ($checkFails ? 'check-fail' : 'check-ok') : '' }}">
                                {{ $row->carriesValues() ? $formatValue($value) : '' }}
                            </td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
