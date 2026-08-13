{{-- Shared marker for records that trace back to a Subsidy that was deleted
     or archived.

     Params:
     $status  - 'deleted' | 'archived' | null
     $ris     - Subsidy/RIS reference (string|null)
     $dr      - Subsidy DR reference (string|null)
     $prefix  - 'FROM' (items) or 'RELATED TO' (transfers / requisitions)
--}}
@if($status)
@php
    $isDeleted = $status === 'deleted';
    $label     = strtoupper((string) $status);
@endphp
<span class="badge subsidy-source-badge {{ $isDeleted ? 'badge-danger' : 'badge-warning' }}"
      data-sub-status="{{ $status }}"
      data-sub-ris="{{ $ris ?? '' }}"
      data-sub-dr="{{ $dr ?? '' }}"
      title="{{ ($isDeleted ? 'Source subsidy was deleted' : 'Source subsidy is archived') }} — click for details."
      style="cursor:pointer;white-space:normal;text-align:left">
    <i class="fas fa-exclamation-triangle"></i>
    {{ $prefix ?? 'FROM' }} {{ $label }} SUBSIDY
</span>
@endif
