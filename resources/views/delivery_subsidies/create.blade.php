@extends('layouts.app')
@section('title', 'New Delivery/Subsidy')
@section('page-title', 'New Delivery/Subsidy')

@section('content')
<div class="page-header">
    <div>
        <h1>New Delivery/Subsidy</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> / Create</div>
    </div>
</div>
@include('delivery_subsidies._create_form', ['createModalOpen' => true, 'modalOnlyPage' => true])
@endsection
