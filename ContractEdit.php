<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use App\Models\SmartContract;
use App\Models\SmartContractAttachment;
use App\Models\SmartContractAttachmentMap;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmailContractInvite;
use App\Mail\EmailContractUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Notification;

class ContractEdit extends Component
{
    use WithFileUploads;
    public $public_name;
    public $contract;
    public $contract_name;
    public $description;
    public $price;
    public $deadline;
    public $contract_files;
    public $provider;
    public $contract_file;
    public $contract_files_edits;
    public $contract_files_data;
    public $meterialId;

    protected $rules = [
        'deadline' => 'required',
        'price' => 'required',
        'contract_name' => 'required',
        'description' => 'required',
    ];
    public function render()
    {
        $contract = SmartContract::where('id',$this->contract)->get();
        $this->contract_files_edits  = DB::table('smart_contract_attachment_maps')
            ->leftjoin('smart_contract_attachments', 'smart_contract_attachments.id', '=', 'smart_contract_attachment_maps.attachment_id')
            ->where('smart_contract_attachment_maps.smart_contract_id',$this->contract)->get();
            $profileId = user::where('id','=',$contract[0]['subscriber_user_id'])->get('profile_id');
            $contractProvider = Profile::where('id',$profileId[0]->profile_id)->get('public_nickname');

        return view('livewire.admin.contract-edit',[
            'pageName'=>'Contracts'
        ]);
}

    public function mount($id){
        $this->contract = $id;
        $userId = Auth::id();
        $contract = SmartContract::where('id',$this->contract)->get();
        if(empty($contract) || $contract[0]['subscriber_confirmed']==1 || $contract[0]['dispute_id']==1){
            return redirect()->to('user/admin/contract-list')->with('message_error','This contract has not been found');
        }

        if(($contract[0]['subscriber_user_id']==$userId) || ($contract[0]['creator_user_id']==$userId )){
            $this->contract_files_edits  = DB::table('smart_contract_attachment_maps')
            ->leftjoin('smart_contract_attachments', 'smart_contract_attachments.id', '=', 'smart_contract_attachment_maps.attachment_id')
            ->where('smart_contract_attachment_maps.smart_contract_id',$this->contract)->get();
            $profileId = user::where('id','=',$contract[0]['subscriber_user_id'])->get('profile_id');
            $contractProvider = Profile::where('id',$profileId[0]->profile_id)->get('public_nickname');

            $this->provider = $contractProvider[0]['public_nickname'];
            $this->contract_name = $contract[0]['title'];
            $this->description = $contract[0]['description'];
            $this->deadline = $contract[0]['deadline'];
            $this->price = $contract[0]['price'];
        }else{
            return redirect()->to('user/admin/contract-list')->with('message_error','This contract has not been found');
        }
    }
     /**
     *Deleting contract material with condition validation
     *
     * 
     */
    public function deleteMaterial($meterialId){
        $userId = Auth::id();
        if($meterialId){
            $MaterialFile = SmartContractAttachment::select('path')->where('id', $meterialId)->get();
            if(!$MaterialFile->isEmpty()){ 
            $smartContractId = SmartContractAttachmentMap::where('attachment_id', $meterialId)->pluck('smart_contract_id')
            ->all();
            $validateContract = SmartContract::where('id',$smartContractId[0])->where('creator_user_id',$userId)->pluck('id');
                if(!$validateContract->isEmpty()){ 
                    Storage::delete('profile_pics/' . $MaterialFile[0]['path']);
                    SmartContractAttachmentMap::where('attachment_id', $meterialId)->delete();  
                    SmartContractAttachment::where('id', $meterialId)->delete();
                    session()->flash('message_mat_del', 'Material deleted.');
                    $this->dispatchBrowserEvent("msg_render_scroll_up");
                }else{
                    session()->flash('message_error', 'Material not found.');
                    $this->dispatchBrowserEvent("msg_render_scroll_up");
                }
            }else{
                session()->flash('message_error', 'Material not found.');
                $this->dispatchBrowserEvent("msg_render_scroll_up");
            }
        }else{
            session()->flash('message_error', 'Material not found.');
            $this->dispatchBrowserEvent("msg_render_scroll_up");
        }
    }
     /**
     *Updating Contract and checking already approved or not
     *
     * 
     */
    public function updateContract($contract) {
        $userId = Auth::id();
        $this->validate();
     
        $validateContract = SmartContract::where('id',$contract)->where('creator_user_id',$userId)->pluck('id');
        if(!$validateContract->isEmpty()){
            $contractDetail =  DB::table('smart_contracts')->find($contract);
            if($contractDetail->busychain_recorded==1 && $contractDetail->subscriber_confirmed){
                session()->flash('message_error', 'Contract already approved you cannot modify');
                $this->dispatchBrowserEvent("msg_render_scroll_up");
                return true;
            }
            if(!empty($this->contract_files_data)){
                foreach($this->contract_files_data as $material){
                    $decodeResult = json_decode($material,true);  
                    $contractAttachments = new SmartContractAttachment();
                    $contractAttachmentsMaps = new SmartContractAttachmentMap();
                    
                    $contractAttachments->path = $decodeResult['filename'];
                    $contractAttachments->file_extension = $decodeResult['extension'];
                    $contractAttachments->save();
                    $insertedAttachId = $contractAttachments->id;
                    $contractAttachmentsMaps->smart_contract_id = $contract;
                    $contractAttachmentsMaps->attachment_id = $insertedAttachId;
                    $contractAttachmentsMaps->save();
                }
            }
            $provider = SmartContract::where('id',$contract)->get('subscriber_user_id');
            $providerMail = User::where('id',$provider[0]['subscriber_user_id'])->get('email');
           
            SmartContract::where('id', $contract)->update([
                'title' => $this->contract_name,
                'price' => $this->price,
                'description' => $this->description,
                'deadline' => $this->deadline,
            ]);
            //notifiaction passing 
            $notification = new Notification();
            $notification->user_id = $provider[0]['subscriber_user_id'];
            $notification->heading = 'Update on Job offer';
            $notification->text =    'There is a update on job offer';
            $notification->text .=   '&nbsp;&nbsp; <a target="blank" href="'.url('user/admin/contract-detail/'.$contract).'">View</a>';
            
            $notification->read_indicator =  0;
            $notification->notification_type_id =  1;
            $notification->save();

            $mailData = [
                'title' => 'Update on job offer',
                'url' => url('user/admin/contract-detail/'.$contract)
            ];
            Mail::to($providerMail[0]['email'])->send(new EmailContractUpdate($mailData));
            return redirect()->to('user/admin/contract-list')->with('message', 'Contract Updated successfully'); 
        }else{
            return redirect()->to('user/admin/contract-list')->with('message_error', 'Contract not found'); 
        }
    
    }
    /**
     *Uploading initial drag and drop files
     *
     * @return <json>
     */
    public function fileUpload(Request $request){
        $filesMaterials = $request->file('file');
        $fileInfo = $filesMaterials->getClientOriginalName();
        $filename = pathinfo($fileInfo, PATHINFO_FILENAME);
        $extension = pathinfo($fileInfo, PATHINFO_EXTENSION);
        $file_name= $filename.'-'.time().'.'.$extension;
        $return_data['filename'] = $file_name;
        $return_data['extension'] = $extension;
        $filesMaterials->move(public_path('custom_profile_link'),$file_name);
        // return response()->json(['success'=>$file_name]);
        echo json_encode($return_data);
        exit;
    }
}
