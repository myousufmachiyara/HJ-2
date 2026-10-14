@extends('layouts.app')
@section('title', 'FG Receiving | New')

@section('content')
<div class="row">
  <div class="col-12">
    <form action="{{ route('production_receiving.store') }}" method="POST" enctype="multipart/form-data">
      @csrf
      @include('production-receiving._form')
    </form>
  </div>
</div>
@endsection
