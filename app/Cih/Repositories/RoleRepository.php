<?php
/**
 * Created by PhpStorm.
 * User: iankibet
 * Date: 2016/06/04
 * Time: 7:47 AM
 */

namespace App\Cih\Repositories;

use App\Models\Core\DepartmentPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Shara\Framework\Repositories\UserGroup;

class RoleRepository
{
    protected $path;
    protected $user;
    protected $menus;
    protected $allow = false;
    protected $request;
    protected $is_app = 0;
    protected $common;
    protected $userPermissions;
    protected $allPermissionsFile;

    public function __construct(Request $request = null)
    {
        if($request) {
            $this->request = $request;
            $this->user = Auth::user();
            $this->path = Route::getFacadeRoot()->current()->uri();
        }
    }

    public function check($allow = false)
    {
        if (Auth::user()) {
            $this->authorize([]);
        } else {
            App::abort(403, "Not authorized to access this page/resource/endpoint");
        }

    }

    protected function extractFromAllUrls()
    {
        $user = $this->user;
        $role = $user->role;
        $urls = [];
    
        if ($role == 'admin') {
            $allowed_permissions = [];
            if ($user->department) {
                $modules = DepartmentPermission::where('department_id',$user->department_id)->get();
                foreach ($modules as $module){
//                    $urls = @json_decode($module->urls);
//                    if($urls){
//                        $allowed_urls = array_merge($allowed_urls,$urls);
//                    }
                    $slugs = @json_decode($module->permissions);
                    if($slugs){
                        $permissions[] = $module->module;
                        foreach ($slugs as $slug){
                            $permissions[] = $module->module.'.'.$slug;
                        }
                    }
                }
                $allowed_permissions = json_decode($user->department->permissions);
            }
        } else {
            $allowed_permissions = self::getRolePermissions($user->role);
        }
        $this->allowedPermissions = $allowed_permissions;
        $this->role = $role;
        $urls = [];
        if(file_exists(storage_path('app/permissions/rules.json'))){
            $allPermissionsFile = storage_path('app/permissions/rules.json');
        } else {
            $allPermissionsFile = __DIR__.'/../../templates/allPermissions.json';
        }
        $allPermissions = @json_decode(@file_get_contents($allPermissionsFile));
        $modules = Storage::files('permissions/modules');
        foreach ($modules as $module){
            $moduleArr = explode('/',$module);
            $moduleSlug = str_replace('.json','',$moduleArr[count($moduleArr)-1]);
            $modulePermissions = json_decode(Storage::get($module));
            $allPermissions->$moduleSlug = $modulePermissions;
        }
        foreach ($allPermissions as $slug => $allPermission) {
            $foundUrls = $this->getBlockUrls($allPermission,$slug, $allPermission->main);
            $urls = array_merge($urls,$foundUrls);
        }
        return $urls;
    }
    public function extractRoleUrls($departmentModule,$slugs,$role='admin')
    {
        $urls = [];
        $allowed_permissions = [];
        foreach ($slugs as $slug){
            $allowed_permissions[] = $departmentModule.'.'.$slug;
        }
        $this->allowedPermissions = $allowed_permissions;
        $this->role = $role;
        if(file_exists(storage_path('app/permissions/rules.json'))){
            $allPermissionsFile = storage_path('app/permissions/rules.json');
        } else {
            $allPermissionsFile = __DIR__.'/../../templates/allPermissions.json';
        }
        $allPermissions = @json_decode(@file_get_contents($allPermissionsFile));
        $modules = Storage::files('permissions/modules');
        foreach ($modules as $module){
            $moduleArr = explode('/',$module);
            $moduleSlug = str_replace('.json','',$moduleArr[count($moduleArr)-1]);
            $modulePermissions = json_decode(Storage::get($module));
            $allPermissions->$moduleSlug = $modulePermissions;
        }
        $allPermission = $allPermissions->$departmentModule;
//        $main = $allPermission->main;
//        if(isset($allPermission->urls)){
//            foreach ($allowed_permissions->urls as $url){
//
//            }
//        }
        $foundUrls = $this->getBlockUrls($allPermission,$slug, $allPermission->main);
        $urls = array_merge($urls,$foundUrls);
        return $urls;
    }
    protected $allowedPermissions;
    protected $role;
    protected $urls = [];
    protected $loopLevel = 0;
    protected function getBlockUrls($block, $slug, $mainUrl = null, $urls=[]){
        $mainSlug = $slug;
        $roles = $block->roles;
        $foundUrls = [];
        if (in_array($this->role, $roles)) {
            $extractedUrls = $this->extractMainBlock($block,$mainUrl);
            $foundUrls = array_merge($foundUrls, $extractedUrls);
        }
        $urls = array_merge($urls,$foundUrls);
        if(isset($block->children)){
            foreach ($block->children as $childSlug => $child) {
                if(substr($child->main, 0,1) == '/'){
                    $childrenMain = trim($child->main,'/');
                } else {
                    $childrenMain = trim($mainUrl.'/'.$child->main,'/');
                }
                $extractedUrls = $this->extractMainBlock($child,$childrenMain);
                $foundUrls = array_merge($foundUrls, $extractedUrls);
                $urls = array_merge($urls,$foundUrls);
                $blockUrls = $this->getBlockUrls($child,$childSlug,$childrenMain ,$urls);
                $urls = array_merge($urls,$blockUrls);
            }
        }
        $urls = array_unique($urls);
        return $urls;
    }

    public static function getRolePermissions($role, $returnModules = 0)
    {
        
        $menus = null;
        if(file_exists(storage_path('app/permissions/rules.json'))){
            $allPermissionsFile = storage_path('app/permissions/rules.json');
        } else {
            $allPermissionsFile = __DIR__.'/../../templates/allPermissions.json';
        }
        $modules = Storage::files('permissions/modules');
        $allPermissions = json_decode(file_get_contents($allPermissionsFile));
        $modules_arr = [];
        foreach ($modules as $module){
            $moduleArr = explode('/',$module);
            $moduleSlug = str_replace('.json','',$moduleArr[count($moduleArr)-1]);
            $modulePermissions = json_decode(Storage::get($module));
            $allPermissions->$moduleSlug = $modulePermissions;
            $modules_arr[] = $moduleSlug;
        }
        $slugs = [];
        foreach ($allPermissions as $slug => $allPermission) {
            $modules_arr[] = $slug;
            if(!$returnModules){
                $new_slugs = self::getPermissionSlugs($allPermission, $slug,$role);
                $slugs = array_merge($slugs, $new_slugs);
            }
        }
        if($returnModules){
            return $modules_arr;
        }
        return $slugs;
    }
    public static function getModulePermissions($role, $reqModule)
    {
        
        $menus = null;
        if(file_exists(storage_path('app/permissions/rules.json'))){
            $allPermissionsFile = storage_path('app/permissions/rules.json');
        } else {
            $allPermissionsFile = __DIR__.'/../../templates/allPermissions.json';
        }
        $modules = Storage::files('permissions/modules');
        $allPermissions = json_decode(file_get_contents($allPermissionsFile));
        $modules_arr = [];
        
        foreach ($modules as $module){
            $moduleArr = explode('/',$module);
            $moduleSlug = str_replace('.json','',$moduleArr[count($moduleArr)-1]);
            $modulePermissions = json_decode(Storage::get($module));
            $allPermissions->$moduleSlug = $modulePermissions;
        }
        try{
            $slugs = self::getPermissionSlugs($allPermissions->$reqModule, null,$role);
        }catch (\Exception $e){
//            dd($reqModule);
            throw new \Exception("invalid module config in: $reqModule.json");
        }
        return $slugs;
    }

    protected static function getPermissionSlugs($allPermission, $slug,$role,$slugs = []){
        
        $mainSlug = $slug;
        $roles = $allPermission->roles;
        $newSlugs = [];
        if (in_array($role, $roles)) {
            if($mainSlug){
                $newSlugs[] = $mainSlug;
            }
        }
        $slugs = array_merge($slugs,$newSlugs);
        if(isset($allPermission->children)) {
            foreach ($allPermission->children as $childSlug => $childPermission) {
                if($mainSlug){
                    $full_slug = $mainSlug . '.' . $childSlug;
                } else {
                    $full_slug = $childSlug;
                }
                $slugs = self::getPermissionSlugs($childPermission,$full_slug,$role, $slugs);
            }
        }
        return $slugs;
    }

    protected function extractMainBlock($permission, $mainUrl=null)
    {
        $urls = [];
        if (isset($permission->urls)) {
            $permissionUrls = $permission->urls;
            $urls = $this->extractPermissionUrls($permissionUrls, $mainUrl);
        }
        $urls[] = trim($mainUrl,'/');
        return $urls;
    }

    protected function extractPermissionUrls($paths, $main)
    {
        $urls = [];
        foreach ($paths as $path) {
            if (substr($path, 0, 1) != '/') {
                $urls[] = trim($main . '/' . $path, '/');
            } else {
                $urls[] = trim($path, '/');
            }
        }
        return $urls;
    }

    protected function getAllowedUrls(){
        $permissionsRepo = new PermissionsRepository();
        $allowed_urls = session()->get('allowed_urls',[]);
        $permissions = session()->get('permissions',[]);
        $user = $this->user;
        $role = $user->role;
        if($role == 'admin'){
            $modules = DepartmentPermission::where('department_id',$user->department_id)->get();
            foreach ($modules as $module){
                $urls = @json_decode($module->urls);
                if($urls){
                    $allowed_urls = array_merge($allowed_urls,$urls);
                }
                $slugs = @json_decode($module->permissions);
                if($slugs){
                    $permissions[] = $module->module;
                    foreach ($slugs as $slug){
                        $permissions[] = $module->module.'.'.$slug;
                    }
                }
            }
           $allowed_urls = $permissionsRepo->getAllowedUrls($permissions);
        } else {
            $allowed_urls = $permissionsRepo->getAllowedUrls();
        }
        return $allowed_urls;
    }

    protected function authorize($backend)
    {
        $current = preg_replace('/\d/', '', $this->path);
        $current = preg_replace('/{(.*?)}/', '', $current);
        $current = rtrim($current, '/');
        $current = str_replace("//", "/", $current);
        $current = str_replace("//", "/", $current);
        $current = str_replace("//", "/", $current);
        $user = $this->user;
        $allowed_urls = $this->getAllowedUrls();
//        return $allowed_urls;

//        dd($this->path,$business_urls);
//        dd($current,$allowed_urls);
        $allowed_urls[] = '/';
        $allowed_urls[] = '';
        $allowed_urls[] = 'auth/user';
        $allowed_urls[] = 'auth/password';
        if (strpos($current, 'api') !== false) {
            $current = substr_replace($current, '', 0, 4);
        }
        if (!in_array($current, $allowed_urls)) {
            $this->unauthorized();
        }
    }

    public function filterBackend($backend)
    {
        $allowed = [];
        if ($this->user->role == 'business') {
            $group_permissions = $this->user->userGroup->permissions;

        } elseif ($this->user->role == 'super') {
            $group_permissions = json_decode($this->user->group->permissions);
        }
        if (!$group_permissions) {
            $group_permissions = [];
        }
        foreach ($backend as $single) {
            if (in_array($single->slug, $group_permissions)) {
                $allowed[] = $single;
                if ($single->slug == 'user_management') {
                    $user_groups = UserGroup::all(['id', 'name']);
                    foreach ($user_groups as $group) {
                        $menu = new \stdClass();
                        $menu->url = "users/view/" . $group->id;
                        $menu->label = $group->name;
                        $single->children[] = $menu;
                    }
                }
            }

        }
        return $allowed;
    }

    protected function separateLinks($raw_menu)
    {
        $links = [];
        foreach ($raw_menu as $single) {
            $main_url = "";
            if (isset($single->url)) {
                $child_url = preg_replace('/\d/', '', $single->url);
                $child_url = rtrim($child_url, '/');
                $main_url = $child_url;
                if (!in_array($child_url, $links))
                    $links[] = $child_url;
            } else if (isset($single->main_url)) {
                $child_url = preg_replace('/\d/', '', $single->main_url);
                $child_url = rtrim($child_url, '/');
                $main_url = $child_url;
                if (!in_array($child_url, $links))
                    $links[] = $child_url;
            } else if (isset($single->main)) {
                $child_url = preg_replace('/\d/', '', $single->main);
                $child_url = rtrim($child_url, '/');
                $main_url = $child_url;
                if (!in_array($child_url, $links))
                    $links[] = $child_url;
            }
            if (@$single->type == 'many') {
                foreach ($single->children as $child) {
                    $child_url = preg_replace('/\d/', '', $child->url);
                    $child_url = rtrim($child_url, '/');
                    if (!in_array($child_url, $links))
                        $links[] = $child_url;
                }
                if (isset($single->urls)) {
                    foreach ($single->urls as $url) {
                        $url = rtrim($url, '/');
                        $url = preg_replace('/\d/', '', $url);
                        if (!in_array($url, $links))
                            $links[] = $url;
                    }
                }

                if (isset($single->subs) && isset($single->main)) {
                    $child_url = preg_replace('/\d/', '', $single->main);
                    $child_url = rtrim($child_url, '/');
                    $main_url = $child_url;
                    foreach ($single->subs as $url) {
                        $url = rtrim($url, '/');
                        $url = preg_replace('/\d/', '', $url);
                        $url = $main_url . '/' . $url;
                        if (!in_array($url, $links))
                            $links[] = $url;
                    }
                }
            } else {
                if (isset($single->menus->url)) {
                    $child_url = preg_replace('/\d/', '', $single->menus->url);
                    $child_url = rtrim($child_url, '/');
                    $main_url = $child_url;
                    if (!in_array($child_url, $links))
                        $links[] = $child_url;
                }
                if (isset($single->subs)) {
                    foreach ($single->subs as $url) {
                        $url = rtrim($url, '/');
                        $url = preg_replace('/\d/', '', $url);
                        $url = $main_url . '/' . $url;
                        if (!in_array($url, $links))
                            $links[] = $url;
                    }
                }
            }
            if (isset($single->urls))
                foreach ($single->urls as $url) {
                    $url = rtrim($url, '/');
                    $url = preg_replace('/\d/', '', $url);
                    if (!in_array($url, $links))
                        $links[] = $url;
                }
            if (isset($single->child_permissions)) {
                foreach ($single->child_permissions as $child_permission) {
                    if (in_array($single->slug . '.' . $child_permission->slug, $this->userPermissions)) {
                        $url = $child_permission->url;
                        if (substr($url, 0, 1) !== '/') {
                            //main url
                            $url = $main_url . '/' . $url;
                        }
                        $url = rtrim($url, '/');
                        $url = preg_replace('/\d/', '', $url);
                        $url = ltrim($url, '/');
                        if (!in_array($url, $links))
                            $links[] = $url;
                    }
                }
            }
        }
        return $links;
    }

    protected function sanitizeBusinessUrls($urls)
    {

    }

    public function unauthorized()
    {
        $common_paths = ['logout', 'login', 'register'];
        $path = $this->path;
        if (!in_array($path, $common_paths)) {
            App::abort(403, "Not authorized to access this page/resource/endpoint");
            die('You are not authorized to perform this action');
        }
    }
}
