@extends('layouts.app')
@section('title', 'PDC | Issue Cheque')
@section('content')
<div class="row"><div class="col-12">
  <form action="{{ route('pdc_cheques.store') }}" method="POST">
    @csrf
    @include('pdc._form')
  </form>
</div></div>
@endsection
