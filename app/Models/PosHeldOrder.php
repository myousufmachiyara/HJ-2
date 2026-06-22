<?php
// app/Models/PosHeldOrder.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PosHeldOrder extends Model
{
    protected $fillable = ['user_id','customer_id','label','cart','total'];
    public function customer() { return $this->belongsTo(ChartOfAccounts::class, 'customer_id'); }
}