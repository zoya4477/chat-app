<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkspaceMember extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'workspace_id', 'user_id', 'role', 'permissions', 'is_active', 
        'joined_at', 'invited_at', 'last_active_at'
    ];
    
    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'joined_at' => 'datetime',
        'invited_at' => 'datetime',
        'last_active_at' => 'datetime'
    ];
    
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function hasPermission($permission)
    {
        if ($this->role === 'admin') return true;
        return isset($this->permissions[$permission]) && $this->permissions[$permission] === true;
    }
}