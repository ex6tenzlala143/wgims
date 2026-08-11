@extends('layouts.app')
@section('title', 'New Requisition (RIS)')
@section('page-title', 'New Requisition')

@section('content')
<div class="page-header">
    <div>
        <h1>New Requisition (RIS)</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Create</div>
    </div>
</div>
@include('requisitions._create_form', ['createModalOpen' => true, 'modalOnlyPage' => true])
@endsection
