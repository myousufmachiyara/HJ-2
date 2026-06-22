
@extends('layouts.app')

@section('title', 'Unauthorized')

@section('content')
    <section class="body">		
        <div class="inner-wrapper cust-pad">
            <section role="main" class="content-body" style="margin:0px">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="main-error mb-3">
                            <h2 class="error-code text-dark text-center font-weight-semibold m-0"> <i class="fa fa-ban"></i> Access Denied </h2>
                            <p class="error-explanation text-center">We're sorry, you are not allowed to access this page.</p>
                            <a href="javascript:history.back()" class="text-center" style="display:block"><i class="fa fa-arrow-left"></i> Go Back<a>
                        </div>
                    </div>
                </div>
            </section>		
        </div>
    </section>
@endsection
        