<?php

namespace App\Http\Controllers\WebApi\Auth;

use App\Http\Controllers\Api\Auth\IssueTokenTrait;
use App\Http\Controllers\Controller;
use App\Mail\ForgetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\HasApiTokens;
use App\User;
use App\Setting;
use Mail;
use Validator;
use Hash;
use Socialite;

class LoginController extends Controller
{

    use IssueTokenTrait;

	private $client;

	public function __construct(){
		$this->client = Client::find(2);
	}

    public function login(Request $request)
    {

    	$this->validate($request, [
    		'email' => 'required',
    		'password' => 'required'
    	]);
        
        $authUser = User::where('email', $request->email)->first();
        if(isset($authUser) && $authUser->status == 0){
            return response()->json('Blocked User', 401); 
        }
        else{

            $setting = Setting::first();

            if(isset($authUser))
            {
                if($setting->verify_enable == 0)
                {
                    if(isset($request->role))
                    {
                        if($authUser->role == 'instructor')
                        {
                            if (isset($request->device_token)) {
                                $authUser->device_token = $request->device_token;
                                $authUser->save();
                            }
                            return $this->issueToken($request, 'password');
                        }
                        else{
                            return response()->json('Invalid Login', 404);  
                        }
                    }
                    else{
                        if (isset($request->device_token)) {
                            $authUser->device_token = $request->device_token;
                            $authUser->save();
                        }
                        return $this->issueToken($request, 'password');  
                    }

                      
                }
                else
                {
                    if($authUser->email_verified_at != NULL)
                    {
                        if(isset($request->role))
                        {
                            if($authUser->role == 'instructor')
                            {
                                if (isset($request->device_token)) {
                                    $authUser->device_token = $request->device_token;
                                    $authUser->save();
                                }
                                return $this->issueToken($request, 'password');
                            }
                            else{
                                return response()->json('Invalid Login', 404);  
                            }
                        }
                        else{
                            if (isset($request->device_token)) {
                                $authUser->device_token = $request->device_token;
                                $authUser->save();
                            }
                            return $this->issueToken($request, 'password');
                        }
                        

                          
                    }
                    else
                    {
                        return response()->json('Verify your email', 402); 
                    }
                }

            }
            else{

                return response()->json('invalid User login', 401);

            }
            
        }

    }

  
  


    public function refresh(Request $request){
    	$this->validate($request, [
    		'refresh_token' => 'required'
    	]);

    	return $this->issueToken($request, 'refresh_token');
    }
    
    public function forgotApi(Request $request)
    { 
        $user = User::whereEmail($request->email)->first();
        if($user){

            $uni_col = array(User::pluck('code'));
            do {
              $code = str_random(5);
            } while (in_array($code, $uni_col));            
            try{
                $config = Setting::findOrFail(1);
                $logo = $config->logo;
                $email = $config->wel_email;
                $company = $config->project_title;
                // Mail::send('forgotemail', ['code' => $code, 'logo' => $logo, 'company'=>$company], function($message) use ($user, $email) {
                //     $message->from($email)->to($user->email)->subject('Reset Password Code');
                // });
                Mail::to($request->email)->send(new ForgetPassword($code, $user));

                $user->code = $code;
                $user->save();
                return response()->json('ok', 200);
            }
            catch(\Swift_TransportException $e){
                return response()->json('Mail Sending Error', 400);
            }
        }
        else{          
            return response()->json('user not found', 401);  
        }
    }

    public function verifyApi(Request $request)
    { 
        if( ! $request->code || ! $request->email)
        {
            return response()->json('email and code required', 449);
        }

        $user = User::whereEmail($request->email)->whereCode($request->code)->first();

        if( ! $user)
        {            
            return response()->json('not found', 401);
        }
        else{
            $user->code = null;
            $user->save();
            return response()->json('ok', 200);
        }
    }

    public function resetApi(Request $request)
    { 

        $validator = Validator::make($request->all(), [
            'password' => 'required|confirmed|min:6',
        ]);

        $user = User::whereEmail($request->email)->first();

        if($user){

            $user->update(['password' => bcrypt($request->password)]);

            $user->save(); 
            
            return response()->json('ok', 200);
        }
        else{          
            return response()->json('not found', 401);
        }
    }

    public function logoutApi()
    {

        $token = Auth::user()->token();
        $token->revoke();
        $response = ['message' => 'You have been successfully logged out!'];
        return response($response, 200);

    }

    public function redirectToblizzard_sociallogin($provider){
        return Socialite::driver($provider)->stateless()->redirect();
    }


    public function blizzard_sociallogin(Request $request, $provider)
    {

        if(!$request->has('code') || $request->has('denied')) {
            return response()->json('Code not found !', 401); 
        }


        try{

           return Socialite::driver($provider)->stateless()->user();

        }catch(\Exception $e){

           return response()->json($e->getMessage(),401);
        }

        $authUser = $this->findOrCreateUser($user, $provider);

        //check status and block condition and return response

        // return msg your a/c is not active.

        if(isset($authUser) &&  $authUser->status == '0'){
            return response()->json('Blocked User', 401); 
        }

        else{

             $token = $authUser
                     ->createToken(config('app.name') . ' Password Grant Client')
                     ->accessToken;

            return response()->json(['accessToken' => $token], 200); 



        }

        


        // return $token
    }

    public function findOrCreateUser($user, $provider)
    {
        if($user->email == Null){
            $user->email = $user->id.'@facebook.com';
        }

        $authUser = User::where('email', $user->email)->first();
        $providerField = "{$provider}_id";

        if($authUser){
            if ($authUser->{$providerField} == $user->id) {
                $authUser->email_verified_at = \Carbon\Carbon::now()->toDateTimeString();
                $authUser->save();
                return $authUser;
            }
            else{
                $authUser->{$providerField} = $user->id;
                $authUser->email_verified_at = \Carbon\Carbon::now()->toDateTimeString();
                $authUser->save();
                return $authUser;
            }
        }

        if($user->avatar != NULL && $user->avatar != ""){
            $fileContents = @file_get_contents($user->getAvatar());
            $user_profile = File::put(public_path() . '/images/user_img/' . $user->getId() . ".jpg", $fileContents);
            $name = $user->getId() . ".jpg";
        }
        else {
            $name = NULL;
        }

        $verified = \Carbon\Carbon::now()->toDateTimeString();

        $setting = Setting::first();

        $auth_user = User::create([
            'fname'              => $user->name,
            'email'              => $user->email,
            'user_img'           => $name,
            'email_verified_at'  => $verified,
            'password'           => Hash::make('password'),
            $providerField       => $user->id,
        ]);


        if($setting->w_email_enable == 1){
            try{
               
                Mail::to($auth_user['email'])->send(new WelcomeUser($auth_user));
               
            }
            catch(\Swift_TransportException $e){

            }
        }



        return $auth_user;



    }
    
}
