@extends('layouts.app')
@section('title', 'Stock Movement | Edit')

@section('content')
<div class="row">
  <div class="col-12">
    <form action="{{ route('stock_transfer.update', $transfer->id) }}" method="POST">
      @csrf
      @method('PUT')
      @include('stock-transfer._form')
    </form>
  </div>
</div>
@endsection
