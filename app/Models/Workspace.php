<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'name', 'slug', 'description', 'logo', 'owner_id', 'settings', 'is_active'
    ];
    
    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean'
    ];
    
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
    
    public function members()
    {
        return $this->hasMany(WorkspaceMember::class);
    }
    
    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_members')
                    ->withPivot('role', 'permissions', 'is_active')
                    ->withTimestamps();
    }
    
    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    // ✅ YEH MISSING THA
    public function channels()
    {
        return $this->hasMany(Channel::class);
    }
    
    public function messages()
    {
        return $this->hasMany(Message::class);
    }
    
    public function getMembersCountAttribute()
    {
        return $this->members()->count();
    }
    
    // ✅ Owner bhi check karo
    public function isAdmin($userId)
    {
        if ((int) $this->owner_id === (int) $userId) {
            return true;
        }
        $member = $this->members()->where('user_id', $userId)->first();
        return $member && in_array($member->role, ['admin', 'owner']);
    }
    
    // ✅ Owner bhi check karo
    public function isMember($userId)
    {
        if ((int) $this->owner_id === (int) $userId) {
            return true;
        }
        return $this->members()->where('user_id', $userId)->exists();
    }


}