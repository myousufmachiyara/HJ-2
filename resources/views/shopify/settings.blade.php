{{-- ═══════════════════════════════════════════════════════════════
     resources/views/shopify/settings.blade.php
     FIX: Store status badge now reflects actual status value
          instead of always showing "Active" / green.
     ═══════════════════════════════════════════════════════════════ --}}

@extends('layouts.app')
@section('title', 'Shopify Integration | Settings')
@section('content')
<div class="row">

    {{-- ── Left column: Add New Store ── --}}
    <div class="col-md-4">
        <section class="card">
            <header class="card-header">
                <h2 class="card-title">Add New Store</h2>
            </header>
            <div class="card-body">

                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                <form action="{{ route('shopify.store') }}" method="POST">
                    @csrf

                    <div class="form-group mb-3">
                        <label class="form-label fw-semibold">Store Name <span class="text-danger">*</span></label>
                        <input type="text" name="shop_name"
                            class="form-control @error('shop_name') is-invalid @enderror"
                            placeholder="e.g. Khanak Store"
                            value="{{ old('shop_name') }}" required>
                        @error('shop_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label fw-semibold">Shop URL <span class="text-danger">*</span></label>
                        <input type="text" name="shop_url"
                            class="form-control @error('shop_url') is-invalid @enderror"
                            placeholder="yourstore.myshopify.com"
                            value="{{ old('shop_url') }}" required>
                        <small class="text-muted">Use your <code>.myshopify.com</code> URL.</small>
                        @error('shop_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label fw-semibold">Client ID <span class="text-danger">*</span></label>
                        <input type="text" name="client_id"
                            class="form-control @error('client_id') is-invalid @enderror"
                            placeholder="3070ece2d1131d..."
                            value="{{ old('client_id') }}" required>
                        <small class="text-muted">Found in Dev Dashboard → your app → Settings → Credentials</small>
                        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label fw-semibold">Client Secret <span class="text-danger">*</span></label>
                        <input type="password" name="client_secret"
                            class="form-control @error('client_secret') is-invalid @enderror"
                            placeholder="shpss_xxxxxxxxxx" required>
                        <small class="text-muted">Click the eye icon next to Secret in Dev Dashboard → Settings</small>
                        @error('client_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="alert alert-info py-2 small">
                        <strong>Note:</strong> After clicking Connect, you'll be redirected to Shopify to approve access.
                        You'll be brought back automatically. The import will start in the background.
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        Connect Store →
                    </button>
                </form>
            </div>
        </section>
    </div>

    {{-- ── Right column: Connected Stores + Sync History ── --}}
    <div class="col-md-8">

        {{-- Connected Stores --}}
        <section class="card">
            <header class="card-header">
                <h2 class="card-title">Connected Stores</h2>
            </header>
            <div class="card-body">
                @if($stores->isEmpty())
                    <p class="text-muted mb-0">No stores connected yet.</p>
                @else
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Store</th>
                            <th>URL</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stores as $store)
                        <tr>
                            <td>{{ $store->shop_name }}</td>
                            <td><small>{{ $store->shop_url }}</small></td>
                            {{-- FIX: badge now reflects the real status column value
                                 instead of hardcoding "Active" / bg-success for all rows. --}}
                            <td>
                                @php
                                    $badgeClass = match($store->status) {
                                        'connected' => 'bg-success',
                                        'pending'   => 'bg-secondary',
                                        'failed'    => 'bg-danger',
                                        default     => 'bg-warning text-dark',
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }}">
                                    {{ ucfirst($store->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    {{-- Manual Sync (only shown when connected) --}}
                                    @if($store->status === 'connected')
                                    <form action="{{ route('shopify.store.sync', $store->id) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-info text-white">
                                            Sync Now
                                        </button>
                                    </form>
                                    @endif
                                    {{-- Disconnect --}}
                                    <form action="{{ route('shopify.store.delete', $store->id) }}" method="POST"
                                          onsubmit="return confirm('Disconnect {{ addslashes($store->shop_name) }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            Disconnect
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </section>

        {{-- Sync History --}}
        <section class="card mt-4">
            <header class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title mb-0">Sync History</h2>
                {{-- Auto-refresh every 15s while any job is processing --}}
                @if(\App\Models\ShopifySyncLog::whereIn('status', ['pending', 'processing'])->exists())
                <small class="text-muted">
                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                    Refreshing…
                    <meta http-equiv="refresh" content="15">
                </small>
                @endif
            </header>
            <div class="card-body">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Store</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(\App\Models\ShopifySyncLog::with('store')->latest()->take(10)->get() as $log)
                        <tr>
                            <td>{{ $log->store?->shop_name ?? '—' }}</td>
                            <td>{{ $log->created_at->format('M d, H:i') }}</td>
                            <td>
                                @if($log->status === 'completed')
                                    <span class="badge bg-success">Success</span>
                                @elseif($log->status === 'processing')
                                    <span class="badge bg-info text-white">Processing…</span>
                                @elseif($log->status === 'pending')
                                    <span class="badge bg-secondary">Queued</span>
                                @else
                                    <span class="badge bg-danger">Failed</span>
                                @endif
                            </td>
                            <td>{{ $log->synced_products }} / {{ $log->total_products }}</td>
                            <td class="small text-danger">{{ Str::limit($log->error_message, 60) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center">No sync history yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

    </div>
</div>
@endsection