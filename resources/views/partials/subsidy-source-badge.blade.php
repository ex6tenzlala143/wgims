{{-- Shared marker for records that trace back to a Subsidy that was deleted.

     Params:
     $status  - 'deleted' | null
     $ris     - Subsidy/RIS reference (string|null)
     $dr      - Subsidy DR reference (string|null)
     $code    - permanent Subsidy ID, e.g. SUB-000007 (string|null)
     $prefix  - 'FROM' (items) or 'RELATED TO' (transfers / requisitions)
--}}
@if($status === 'deleted')
<span class="badge badge-danger subsidy-source-badge"
      data-sub-status="{{ $status }}"
      data-sub-code="{{ $code ?? '' }}"
      data-sub-ris="{{ $ris ?? '' }}"
      data-sub-dr="{{ $dr ?? '' }}"
      title="Source subsidy was deleted — click for details."
      style="cursor:pointer;white-space:normal;text-align:left">
    <i class="fas fa-exclamation-triangle"></i>
    {{ $prefix ?? 'FROM' }} DELETED SUBSIDY
    @if($code ?? null)<span style="opacity:.85;font-weight:600">({{ $code }})</span>@endif
</span>
@endif
