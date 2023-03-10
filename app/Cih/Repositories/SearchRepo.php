<?php

namespace App\Cih\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Str;
use function redirect;
use function request;
use function response;
use function storage_path;

class SearchRepo
{
    protected static $data;
    protected static $request_data;
    protected static $instance;
    protected static $tmp_key;
    protected static $tmp_value;
    public static function of($model,$base_tbl=null,$search_keys=null){
        self::$instance = new self();
        $request_data = request()->all();
        if($base_tbl){
            $request_data['base_table'] = $base_tbl;
        }
        if($search_keys){
            $request_data['keys'] = $search_keys;
        }

        self::$request_data = $request_data;
        if(isset($request_data['start_d'])){

            if($request_data['has_range']){
                $start_date = Carbon::createFromTimestamp(strtotime($request_data['start_d']))->startOfDay();
                $end_date = Carbon::createFromTimestamp(strtotime($request_data['end_d']))->endOfDay();
//              dd($start_date,$end_date,$request_data);
                if(!$request_data['base_table']){
                    $model = $model->where([
                        ['created_at','>=',$start_date],
                        ['created_at','<=',$end_date]
                    ]);
                }else{
//                    dd($start_date,$end_date);
                    $model = $model->where([
                        [$request_data['base_table'].'.created_at','>=',$start_date],
                        [$request_data['base_table'].'.created_at','<=',$end_date]
                    ]);
                }
            }
        }

        if(isset($request_data['filter_value'])){
            $value = $request_data['filter_value'];

            $model = $model->where(function($query) use ($request_data,$value){
                $index = 0;
                foreach($request_data['keys'] as $key){
                    if(!strpos($key,'.') && $request_data['base_table'] != null)
                        $key = $request_data['base_table'].'.'.$key;
                    if($index == 0){
                        $query->where([
                            [$key,'like','%'.$value.'%']
                        ]);
                    }else{
                        $query->orWhere([
                            [$key,'like','%'.$value.'%']
                        ]);
                    }
                    $index++;
                }

            });
        }
        $request_data = self::$request_data;
        if(isset($request_data['order_by']) && isset($request_data['order_method'])){
            $model = $model->orderBy($request_data['order_by'],$request_data['order_method']);
        }else{
            $model = $model->orderBy('created_at','desc');

        }

        if(isset($request_data['all'])){
            $data = $model->get();
        }else{
            if(!isset($request_data['download_csv'])){
                if(isset($request_data['per_page'])){
                    $data =  $model->paginate(round($request_data['per_page'],0));
                }else{
                    $data= $model->paginate(10);
                }
            }
        }
        self::$data = $data;
        return self::$instance;
    }

    public static function make($pagination = true){
        $data = self::$data;
        $request_data = self::$request_data;
        if(isset($request_data['all'])){
            return $data;
        }
        unset($request_data['page']);
        $data->appends($request_data);
//        if($pagination){
//            $pagination = $data->links()->__toString();
//            $data = $data->toArray();
//            $data['pagination'] = $pagination;
//        }
        if(isset($request_data['download_csv'])){
            $csv_data = $data['data'];
            if(count($csv_data)){
                $single = $csv_data[0];
                unset($single['action']);
                $keys = array_keys($single);
                $file_path = storage_path("app/tmp/download_".time().Str::random(5).'.csv');
                $tmp = fopen($file_path,'w');
                fputcsv($tmp,$keys);
                foreach ($csv_data as $row){
                    unset($row['action']);
                    fputcsv($tmp,array_values($row));
                }
                fclose($tmp);
                $name = null;
                if($request_data['base_table']){
                    $name = $request_data['base_table'].date('_Y-m-d_h_i_a').'.csv';
                }
                return response()->download($file_path,$name)->deleteFileAfterSend();

            }else{
                return redirect()->back()->with('notice',['type'=>'error','message'=>'No records found']);
            }
        }
        return $data;

    }

    public static function addColumn($column,$function){
        $records = self::$data;
        foreach($records as $index=>$record){
            $record->$column = $function($record);
            $records[$index] = $record;
        }
        self::$data = $records;
        return self::$instance;
    }
}
