@extends('layouts.app')
@section('title', 'Correction History — RIS ' . ($requisition->ris_code ?? $requisition->ris_id) . ' / ' . $requisition->ris_number)
@section('page-title', 'Correction History')
@php use Illuminate\Support\Str; @endphp

@section('content')
<div class="page-header">
    <div>
        <h1>Correction History — RIS {{ $requisition->ris_code ?? $requisition->ris_id }} / {{ $requisition->ris_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('requisitions.index') }}">Requisitions</a> /
            <a href="{{ route('requisitions.show', $requisition->id) }}">RIS {{ $requisition->ris_code ?? $requisition->ris_id }} / {{ $requisition->ris_number }}</a> /
            Correction History
        </div>
    </div>
    <a href="{{ route('requisitions.show', $requisition->id) }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to RIS
    </a>
</div>

{{-- Header summary --}}
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:13px">
        <div>
            <div style="color:var(--text-muted);margin-bottom:2px">Office</div>
            <div style="font-weight:600">{{ $requisition->office ?? '—' }}</div>
        </div>
        <div>
            <div style="color:var(--text-muted);margin-bottom:2px">Total Requested</div>
            <div style="font-weight:600">{{ number_format($requisition->totalRequested(), 2) }}</div>
        </div>
        <div>
            <div style="color:var(--text-muted);margin-bottom:2px">Status</div>
            <div><span class="badge {{ $requisition->getStatusBadgeClass() }}">{{ $requisition->getStatusLabel() }}</span></div>
        </div>
    </div>
</div>

@if($logs->isEmpty())
<div class="card">
    <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
        <i class="fas fa-history" style="font-size:36px;margin-bottom:12px;display:block"></i>
        No corrections have been recorded for this RIS yet.
    </div>
</div>
@else
<div class="card">
    <div class="card-header">
        <h3>Correction History <span style="font-weight:400;color:var(--text-muted);font-size:13px">({{ $logs->total() }} {{ Str::plural('entry', $logs->total()) }})</span></h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="min-width:160px">Date / Time</th>
                    <th>Corrected By</th>
                    <th>Changes</th>
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
                                $headerLabels = [
                                    'entity_name'   => 'Entity Name',
                                    'fund_cluster'  => 'Fund Cluster',
                                    'office'        => 'Office',
                                    'division'      => 'Division',
                                    'province'      => 'Province',
                                    'municipality'  => 'Municipality',
                                    'responsibility_center_code' => 'Resp. Center Code',
                                    'purpose'       => 'Purpose',
                                    'date_requested'=> 'Date Requested',
                                    'requested_by_name' => 'Requested By',
                                    'requested_by_designation' => 'Requested By Designation',
                                    'total_requested' => 'Total Requested Qty',
                                    'status'        => 'RIS Status',
                                ];
                            @endphp
                            <ul style="margin:0;padding-left:16px;font-size:12px">
                                @foreach($log->changed_fields as $field => $change)
                                    @if(Str::startsWith($field, 'items.'))
                                        <li>
                                            <strong>{{ Str::endsWith($field, '.item') ? 'Item' : 'Requested Qty' }}</strong>
                                            @if(Str::endsWith($field, '.quantity_requested'))
                                                for <em>{{ $change['old'] }}</em>:
                                                <span style="color:var(--danger)">{{ number_format((float) $change['old']) }}</span>
                                                → <span style="color:var(--success)">{{ number_format((float) $change['new']) }}</span>
                                            @else
                                                for line:
                                                <span style="color:var(--danger)">{{ $change['old'] ?: '—' }}</span>
                                                → <span style="color:var(--success)">{{ $change['new'] ?: '—' }}</span>
                                            @endif
                                        </li>
                                    @elseif(isset($headerLabels[$field]))
                                        <li>
                                            <strong>{{ $headerLabels[$field] }}</strong>:
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
