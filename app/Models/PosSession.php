<?php
// app/Models/PosSession.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PosSession extends Model
{
    protected $fillable = ['user_id','session_date','opening_cash','closing_cash','status','closed_at'];
    public function user() { return $this->belongsTo(User::class); }
}