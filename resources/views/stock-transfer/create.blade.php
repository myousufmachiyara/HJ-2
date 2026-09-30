@extends('layouts.app')
@section('title', 'Stock Movement | New')

@section('content')
<div class="row">
  <div class="col-12">
    <form action="{{ route('stock_transfer.store') }}" method="POST">
      @csrf
      @include('stock-transfer._form')
    </form>
  </div>
</div>
@endsection
