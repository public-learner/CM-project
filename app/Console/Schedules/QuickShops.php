<?php


namespace VanguardLTE\Console\Schedules;


use Carbon\Carbon;
use jeremykenedy\LaravelRoles\Models\Role;
use VanguardLTE\OpenShift;
use VanguardLTE\Progress;
use VanguardLTE\QuickShop;
use VanguardLTE\Shop;
use VanguardLTE\ShopCategory;
use VanguardLTE\ShopCountry;
use VanguardLTE\ShopDevice;
use VanguardLTE\ShopOS;
use VanguardLTE\ShopUser;
use VanguardLTE\SMSBonus;
use VanguardLTE\Statistic;
use VanguardLTE\Support\Enum\UserStatus;
use VanguardLTE\Task;
use VanguardLTE\User;
use VanguardLTE\WelcomeBonus;

class QuickShops
{

    public $max_time_in_sec;

    public function __construct($max_time_in_sec=5){
        $this->max_time_in_sec = $max_time_in_sec;
    }

    public function __invoke()
    {
        // TODO: Implement __invoke() method.

        $start = microtime(true);

        $task = QuickShop::first();
        if($task){
            //$task->update(['finished' => 1]);

            $data = json_decode($task->data, true);

            $sleep = 0;

            $shop = [
                'name' => $data['name'],
                'percent' => $data['percent'],
                'frontend' =>  $data['frontend'],
                'orderby' => $data['orderby'],
                'currency' => $data['currency'],
                'categories' =>  $data['categories'],
                'balance' => $data['balance'],
                'country' => $data['country'],
                'os' => $data['os'],
                'device' => $data['device'],
                'access' =>  $data['access'],
            ];

            $shops = Shop::where('name', $data['name'])->count();
            if($shops){
                $task->delete();
                return;
            }

            
            $distributor = $data['distributor'];
            $agent = $data['agent'];
            $users = $data['users'];

            $usersBalance = floatval($users['balance'] * $users['count']);
            $agentBalance = floatval($agent['balance']);
            $distributorBalance = floatval($distributor['balance']);
            $shopBalance = floatval($shop['balance']);

            $distributor['balance'] = $agent['balance'] = $shop['balance'] = 0;

            $roles = Role::get();

            $parent = User::find(1);

            // create distributor
            $distributor = User::create($distributor + [
                'parent_id' => $parent->id, 
                'role_id' => 3, 
                'ancestry' => $parent->ancestry.$parent->id.'/',
                'created_at' => time() + $sleep, 
                'status' => UserStatus::ACTIVE
            ]);
            $distributor->attachRole($roles->find(3));
            $sleep++;

            // create agent
            $agent = User::create($agent + [
                'parent_id' => $distributor->id, 
                'role_id' => 2, 
                'ancestry' => $distributor->ancestry.$distributor->id.'/',
                'created_at' => time() + $sleep, 
                'status' => UserStatus::ACTIVE
            ]);
            $agent->attachRole($roles->find(2));

            // create shop

            $temp = [
                'country' =>  $data['country'],
                'os' => $data['os'],
                'device' => $data['device'],
            ];
            if(count($temp)){
                foreach($temp AS $key=>$item){
                    $shop[$key] = implode(',', $item);
                }
            }

            $shop = Shop::create($shop + ['user_id' => $distributor->id, 'is_blocked' => 0]);
            if( $data['country'] ){
                foreach ($data['country'] AS $country){
                    ShopCountry::create(['shop_id' => $shop->id, 'country' => $country]);
                }
            }
            if( $data['os'] ){
                foreach ($data['os'] AS $os){
                    ShopOS::create(['shop_id' => $shop->id, 'os' => $os]);
                }
            }
            if( $data['device'] ){
                foreach ($data['device'] AS $device){
                    ShopDevice::create(['shop_id' => $shop->id, 'device' => $device]);
                }
            }

            $progress = Progress::where('shop_id', 0)->get();
            if( count($progress)){
                foreach($progress AS $item){
                    $newProgress = $item->replicate();
                    $newProgress->shop_id = $shop->id;
                    $newProgress->save();
                }
            }

            $welcomebonuses = WelcomeBonus::where('shop_id', 0)->get();
            if( count($welcomebonuses)){
                foreach($welcomebonuses AS $item){
                    $newWelcomeBonus = $item->replicate();
                    $newWelcomeBonus->shop_id = $shop->id;
                    $newWelcomeBonus->save();
                }
            }

            $smsbonuses = SMSBonus::where('shop_id', 0)->get();
            if( count($smsbonuses)){
                foreach($smsbonuses AS $item){
                    $newSMSBonus = $item->replicate();
                    $newSMSBonus->shop_id = $shop->id;
                    $newSMSBonus->save();
                }
            }


            $open_shift = OpenShift::create([
                'start_date' => Carbon::now(),
                'balance' => 0,//$shop->balance,
                'user_id' => $agent->id,
                'shop_id' => $shop->id
            ]);

            // add balance
            if($distributorBalance > 0){
                $distributor->addBalance('add', $distributorBalance, $parent);
                sleep(1);
            }

            if($shopBalance > 0){
                $open_shift->increment('balance_in', $shopBalance);
                $distributor->decrement('balance', $shopBalance);
                $shop->increment('balance', $shopBalance);
                Statistic::create(['user_id' => $distributor->id, 'shop_id' => $shop->id, 'sum' => $shopBalance, 'type' => 'add', 'system' => 'shop']);
                sleep(1);
            }

            foreach([$distributor, $agent] AS $user){
                ShopUser::create(['shop_id' => $shop->id, 'user_id' => $user->id]);
                $user->update(['shop_id' => $shop->id]);
            }

            // create users
            $role = Role::find(1);
            for($i=0; $i<$users['count']; $i++){
                $sleep++;
                $number = rand(111111111, 999999999);
                $newUser = User::create([
                    'username' => $number,
                    'password' => $number,
                    'role_id' => $role->id,
                    'status' => 'Active',
                    'shop_id' => $shop->id,
                    'parent_id' => $agent->id,
                    'ancestry' => $agent->ancestry.$agent->id.'/',
                    'created_at' => time() + $sleep
                ]);
                $newUser->attachRole($role);
                if($users['balance'] > 0){
                    $newUser->addBalance('add', $users['balance'], $agent);
                    sleep(1);
                }
                ShopUser::create(['shop_id' => $shop->id, 'user_id' => $newUser->id]);
                $newUser->update(['shop_id' => $shop->id]);
            }

            if( $data['categories']){
                foreach ($data['categories'] AS $category){
                    ShopCategory::create(['shop_id' => $shop->id, 'category_id' => $category]);
                }
            }
            Task::create(['category' => 'shop', 'action' => 'create', 'item_id' => $shop->id, 'shop_id' => $shop->id]);

            $task->delete();
        }


        $time_elapsed_secs = microtime(true) - $start;
        if($time_elapsed_secs > $this->max_time_in_sec){
            Info('------------------');
            Info('QuickShops');
            Info('exec time ' . $time_elapsed_secs . ' sec');
        }

    }

}