@extends('layouts.app')
@section('title', 'Stock Adjustment')

@section('content')
<div class="row">
  <div class="col-12">
    <form action="{{ route('stock_adjustments.store') }}" method="POST">
      @csrf

      @include('stock-adjustments._form')
    </form>
  </div>
</div>
@endsection
