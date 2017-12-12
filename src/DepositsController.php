<?php

namespace Selfreliance\Deposits;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use App\Models\Deposit;
use App\Models\Users_History;
use App\Libraries\DepositProfit\DepositProfit;
use App\Models\Payment_System;
use App\Models\Deposits_Plan;
use App\Libraries\Deposit as DepositLib;
use Carbon\Carbon;
class DepositsController extends Controller
{
    public function block(){
        $days = 8;
        $deposits = Deposit::selectRaw('DATE_FORMAT(`created_at`, \'%Y-%m-%d\') as groupDate, COUNT(`id`) as CntByDate')->orderBy('groupDate', 'desc')->limit($days-1)->groupBy('groupDate')->get();
        $realDataDeposits = [];
        foreach($deposits as $row){
            $realDataDeposits[$row->groupDate] = $row->CntByDate;
        }

        $weekago = Carbon::now()->subDays($days);
        $null_array = [];
        while ($days > 0) {
            $weekago->addDays(1);
            $result = strtolower($weekago->format('Y-m-d'));
            $null_array[$result] = 0;
            $days--;
        }
        $deposits_by_date = array_merge($null_array, $realDataDeposits);
        ksort($deposits_by_date);
        $imploade_data = implode(", ", $deposits_by_date);
        $deposits_today = $deposits_by_date[Carbon::now()->format('Y-m-d')];
        $deposits_yesterday = $deposits_by_date[Carbon::now()->subDays(1)->format('Y-m-d')];
        return view('deposits::block')->with(
            compact('deposits_by_date', 'imploade_data', 'deposits_today', 'deposits_yesterday')
        )->render();
    }
    /**
     * Index
     * @return view home with feedback messages
    */    
    public function index()
    {
        $deposits = Deposit::orderBy('id', 'desc')
            ->leftJoin('deposits__plans', 'deposits__plans.id', '=', 'deposits.plan_id')
            ->leftJoin('payment__systems', 'payment__systems.id', '=', 'deposits.payment_system')
            ->leftJoin('users', 'users.id', '=', 'deposits.user_id')
            ->paginate(10, array(
                'deposits.*', 
                'deposits__plans.percent', 
                'deposits__plans.accruals', 
                'payment__systems.icon', 
                'payment__systems.currency',
                'payment__systems.title',
                'users.email',
                'users.parent_id',
            ));
        $DepositProfit = new DepositProfit();
        foreach ($deposits as $value) {
            if($value->parent_id > 0){
                $value->parent_email = User::where('id', $value->parent_id)->value('email');
            }
            try {
                $value->count_accruals = $DepositProfit->count_accruals($value->id, $value->user_id, 'completed', $value->accruals);    
            } catch (\Exception $e) {
                $value->count_accruals = $value->accruals;
            }

            $value->compleated_percents = round($value->count_accruals*100/$value->accruals, 2);
            
        }
        // dd($deposits);
        return view('deposits::index')->with(['deposits'=>$deposits]);
    }

    public function history($deposit, $user){
        $history = Users_History::
            where('user_id', $user)
            ->addSelect('users__histories.*')
            ->where('type', 'ACCRUALS')
            ->where('status', 'completed')
            ->where('users__histories.data_info->deposit_id', $deposit)
            ->leftJoin('payment__systems', 'payment__systems.id', '=', 'users__histories.payment_system')
            ->addSelect('payment__systems.currency')
            ->leftJoin('users', 'users.id', '=', 'users__histories.user_id')            
            ->addSelect('users.email')
            ->orderBy('id', 'asc')
            ->get();
        return view('deposits::history')->with(['history'=>$history]);
    }

    public function create(){
        $payment_systems = Payment_System::where('purchase', 1)->get();
        $plans = Deposits_Plan::get();
        return view('deposits::create')->with([
            'payment_systems' => $payment_systems,
            'plans'           => $plans
        ]);
    }

    public function store(Request $request){
        $this->validate($request, [
            'user_email'     => 'required|exists:users,email',
            'plan_id'        => 'required|exists:deposits__plans,id',
            'payment_system' => 'required|exists:payment__systems,id',
            'amount'         => 'required',
            'transaction'    => 'required|unique:users__histories,transaction'
        ]);

        try{
            $res = (new DepositLib)
                ->plan($request->input('plan_id'))
                ->amount($request->input('amount'))
                ->user(User::where('email', $request->input('user_email'))->value('id'))
                ->payment_system($request->input('payment_system'))
                ->make_purchase();

            $res->payment_id($res->history->id)
                ->transaction($request->input('transaction'))
                ->create();
            \Session::flash('success','Депозит успешно создан');
            return redirect()->back();
        }catch(\App\Exceptions\NotFoudDepositPlan $e){
            \Session::flash('error',$e->getMessage());
            return redirect()->back()->withInput();
        }
    }
}