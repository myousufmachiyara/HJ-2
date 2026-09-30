@extends('layouts.app')
@section('title', 'FG Receiving | Edit')

@section('content')
<div class="row">
  <div class="col-12">
    <form action="{{ route('production_receiving.update', $receiving->id) }}" method="POST">
      @csrf
      @method('PUT')
      @include('production-receiving._form')
    </form>
  </div>
</div>
@endsection
