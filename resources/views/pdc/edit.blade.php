@extends('layouts.app')
@section('title', 'PDC | Edit Cheque')
@section('content')
<div class="row"><div class="col-12">
  <form action="{{ route('pdc_cheques.update', $cheque->id) }}" method="POST">
    @csrf
    @method('PUT')
    @include('pdc._form')
  </form>
</div></div>
@endsection
