//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FManageInvTypes.h"
#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"


#include "FReportDesign.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageInvTypes *FrmManageInvTypes;
//---------------------------------------------------------------------------
__fastcall TFrmManageInvTypes::TFrmManageInvTypes(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 TJvDBUltimGrid *grid = GridInvoiceTypes;

 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 grid->Color = primary;
 grid->AlternateRowColor = secondary;

 //set main dataset
 dataset = DatasetInvTypes;

 QryDeliveryMethod->Open();
 QryDistAim->Open();
 QryPaymentMethod->Open();
 QueryCustomer->Open();
 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;



 grid->OnDrawColumnCell = this->GridDrawColumnCell;
 grid->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 grid->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 grid->OnTitleBtnClick = this->GridTitleBtnClick;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;

 PageInvoices->ActivePageIndex = 0;


 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageInvTypes::GridInvoiceTypesUserSort(
      TJvDBUltimGrid *Sender, TSortFields &FieldsToSort, AnsiString SortString,
      bool &SortOK)
{

 for(int i=1;i<=SortString.Length();i++)
  if(SortString[i]=='[' || SortString[i]==']')
   SortString[i]=' ';

// How exceptions are written -- this is for joined field
/* if(SortString.Trim().SubString(1,8)=="USERNAME")
  SortString = "CONTRACT."+SortString.Trim();
 else
  SortString = "DOMAINS."+SortString.Trim();
*/
 try
 {
  dataset->SelectSQL->Strings[dataset->SelectSQL->Count - 1] = SortString;
  dataset->Active = true;
 }catch(Exception &e)
 {
  showMessage(AnsiString(e.Message).c_str(),ApplicationName,MB_ICONERROR);
  return;
 }
 
 SortOK=true;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);

 if(transaction->InTransaction)
  transaction->Commit();
  
 if(AnsiString(Owner->ClassName()) == "TFrmMain")
  ((TFrmMain *)Owner)->createChildMenus();
 delete reg;	
}
//---------------------------------------------------------------------------



void __fastcall TFrmManageInvTypes::ToolAddClick(TObject *Sender)
{
 dataset->Insert();
 dataset->FieldByName("INVTYPE_ID")->AsString = "";
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 editInvCode->SetFocus();		
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::ToolDeleteClick(TObject *Sender)
{
 if(dataset->RecordCount==0)
  return;

 try
 {
  dataset->Delete();
 }
 catch(EIBInterBaseError &e)
 {
  if(e.IBErrorCode == 335544466)
   showMessage("’λλες εγγραφές εξαρτώνται απο αυτή την εγγραφή!",ApplicationName,MB_ICONERROR);
  else
   showMessage(e.Message.c_str(),ApplicationName,MB_ICONERROR);
 }			
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::ToolEditClick(TObject *Sender)
{
 dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
 editInvCode->SetFocus();	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::ToolAcceptClick(TObject *Sender)
{
 if( dataset->FieldByName("INVTYPE_ID")->AsString.Length() == 0
	|| dataset->FieldByName("NAME")->AsString.Length() == 0)
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά (κίτρινα) πεδία",ApplicationName, MB_ICONERROR);
  return;
 }

 dataset->Post();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;

 ToolRefreshClick(Sender);
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::editFilenameChange(TObject *Sender)
{
 if(DatasetInvTypes->State == dsEdit || DatasetInvTypes->State == dsInsert)
  DatasetInvTypes->FieldByName("FRM_FILENAME")->AsString = editFilename->FileName;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesAfterCancel(
      TDataSet *DataSet)
{
 editFilename->ReadOnly = true;
 checkShowOnMenu->ReadOnly = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesAfterEdit(TDataSet *DataSet)
{
  editFilename->ReadOnly = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesAfterPost(TDataSet *DataSet)
{
 editFilename->ReadOnly = true;
 checkShowOnMenu->ReadOnly = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesAfterInsert(
      TDataSet *DataSet)
{
 editFilename->ReadOnly = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesAfterOpen(TDataSet *DataSet)
{
 editFilename->ReadOnly = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesAfterScroll(
      TDataSet *DataSet)
{
 editFilename->Text = DataSet->FieldByName("FRM_FILENAME")->AsString;

 if(DataSet->FieldByName("SHOW_ON_MENU")->AsInteger == 1)
  checkShowOnMenu->Checked = true;
 else
  checkShowOnMenu->Checked = false;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::JvExpressButton1Click(TObject *Sender)
{
 if(FileExists(editFilename->FileName)  )
  TFrmReportDesign *frmDesign = new TFrmReportDesign(Owner, editFilename->Text);		
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::checkShowOnMenuClick(TObject *Sender)
{
 if(DatasetInvTypes->State == dsEdit || DatasetInvTypes->State == dsInsert)
 {
  DatasetInvTypes->FieldByName("SHOW_ON_MENU")->AsInteger = checkShowOnMenu->Checked;
 }	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesBeforeEdit(TDataSet *DataSet)
{
 checkShowOnMenu->ReadOnly = false;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::DatasetInvTypesBeforeInsert(
      TDataSet *DataSet)
{
 checkShowOnMenu->ReadOnly = false;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageInvTypes::FormShow(TObject *Sender)
{
 showRecordsFetched();	
}
//---------------------------------------------------------------------------






