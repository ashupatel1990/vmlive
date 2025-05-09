@extends('layout.app')

@section('title')
    Invoice Detail
@endsection
@section('content')
<div class="container-fluid">

    <div class="row">
        <div class="col-12">
            <div class="page-title-box">
                <h4 class="page-title font-weight-bold"> INVOICE DETAIL: #{{ $invoice->invoice_no }}</h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card-box">
                <a href="{{ route('allinvoices') }}" class="btn btn-sm btn-blue waves-effect waves-light ">
                    <i class="mdi mdi-plus-circle"></i> Back
                </a>
            </div>
        </div>
    </div>
</div>
@endsection