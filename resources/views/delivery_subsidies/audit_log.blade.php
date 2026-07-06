@extends('layouts.app')
@section('title', 'Audit Log — RIS #' . $deliverySubsidy->ris_number)
@section('page-title', 'Audit Log')
@php use Illuminate\Support\Str; @endphp

@section('content')
<div class="page-header">
    <div>
        <h1>Audit Log — RIS #{{ $deliverySubsidy->ris_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> /
            <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}">RIS #{{ $deliverySubsidy->ris_number }}</a> /
            Audit Log
        </div>
    </div>
    <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Record
    </a>
</div>

{{-- Header summary --}}
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:13px">
        <div>
            <div style="color:var(--text-muted);margin-bottom:2px">Supplier / Subsidy</div>
            <div style="font-weight:600">{{ $deliverySubsidy->supplier->name ?? '—' }}</div>
        </div>
        <div>
            <div style="color:var(--text-muted);margin-bottom:2px">Warehouse</div>
            <div style="font-weight:600">{{ $deliverySubsidy->warehouse->name ?? '—' }}</div>
        </div>
        <div>
            <div style="color:var(--text-muted);margin-bottom:2px">Status</div>
            <div><span class="badge {{ $deliverySubsidy->getStatusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $deliverySubsidy->status)) }}</span></div>
        </div>
    </div>
</div>

@if($logs->isEmpty())
<div class="card">
    <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
        <i class="fas fa-history" style="font-size:36px;margin-bottom:12px;display:block"></i>
        No edit history recorded for this delivery/subsidy yet.
    </div>
</div>
@else
<div class="card">
    <div class="card-header">
        <h3>Edit History <span style="font-weight:400;color:var(--text-muted);font-size:13px">({{ $logs->total() }} {{ Str::plural('entry', $logs->total()) }})</span></h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="min-width:160px">Date / Time</th>
                    <th>Changed By</th>
                    <th>Fields Changed</th>
                    <th>Cascade Summary</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                <tr>
                    <td style="white-space:nowrap;font-size:12px">
                        {{ $log->created_at->format('M d, Y') }}<br>
                        <span style="color:var(--text-muted)">{{ $log->created_at->format('h:i A') }}</span>
                    </td>
                    <td>
                        <span style="font-weight:600">{{ $log->user->name ?? '—' }}</span><br>
                        <span style="font-size:11px;color:var(--text-muted)">{{ $log->user?->getRoleLabel() ?? '' }}</span>
                    </td>
                    <td>
                        @if(! empty($log->changed_fields))
                            @php
                                $displayFields = [
                                    'ris_number'         => 'RIS No.',
                                    'supplier_id'        => 'Supplier',
                                    'warehouse_id'       => 'Warehouse',
                                    'date'               => 'Date',
                                    'status'             => 'Status',
                                    'quantity_requested' => 'Qty Requested',
                                    'place_of_delivery'  => 'Place of Delivery',
                                    'remarks'            => 'Remarks',
                                ];
                            @endphp
                            <ul style="margin:0;padding-left:16px;font-size:12px">
                                @foreach($log->changed_fields as $field => $change)
                                    @if(Str::startsWith($field, 'line_unit_cost_'))
                                        <li>
                                            <strong>Unit Cost</strong> for <em>{{ $change['item'] ?? 'item' }}</em>:
                                            <span style="color:var(--danger)">{{ is_numeric($change['old']) ? '₱'.number_format($change['old'],2) : $change['old'] }}</span>
                                            → <span style="color:var(--success)">{{ is_numeric($change['new']) ? '₱'.number_format($change['new'],2) : $change['new'] }}</span>
                                        </li>
                                    @elseif(isset($displayFields[$field]))
                                        <li>
                                            <strong>{{ $displayFields[$field] }}</strong>:
                                            <span style="color:var(--danger)">{{ $change['old'] ?: '—' }}</span>
                                            → <span style="color:var(--success)">{{ $change['new'] ?: '—' }}</span>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @else
                            <span style="color:var(--text-muted);font-size:12px">No field details</span>
                        @endif
                    </td>
                    <td style="font-size:12px">
                        @if(! empty($log->cascade_summary))
                            <ul style="margin:0;padding-left:16px">
                                @php
                                    $labels = [
                                        'items_updated'             => 'Items updated',
                                        'stock_card_delivery_rows'  => 'Delivery stock card rows',
                                        'stock_card_transfer_rows'  => 'Transfer stock card rows',
                                        'requisition_rows'          => 'Requisition item rows',
                                        'ris_items_updated'         => 'RIS number synced on items',
                                        'supplier_stock_card_rows'  => 'Supplier name stock card rows',
                                    ];
                                @endphp
                                @foreach($log->cascade_summary as $key => $val)
                                    @if($key === 'items_updated' || $key === 'ris_items_updated')
                                        @php $count = is_array($val) ? count(array_unique($val)) : $val; @endphp
                                        @if($count > 0)
                                        <li>{{ $labels[$key] ?? $key }}: <strong>{{ $count }}</strong></li>
                                        @endif
                                    @elseif(is_numeric($val) && $val > 0)
                                        <li>{{ $labels[$key] ?? $key }}: <strong>{{ $val }}</strong></li>
                                    @endif
                                @endforeach
                            </ul>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <div class="card-footer">{{ $logs->links() }}</div>
    @endif
</div>
@endif
@endsection
