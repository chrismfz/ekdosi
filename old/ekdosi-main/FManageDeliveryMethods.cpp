//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FManageDeliveryMethods.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageDeliveryMeth *FrmManageDeliveryMeth;
//---------------------------------------------------------------------------
__fastcall TFrmManageDeliveryMeth::TFrmManageDeliveryMeth(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);

 GridDeliveryM->Color = primary;
 GridDeliveryM->AlternateRowColor = secondary;

 //set main dataset
 dataset = DatasetDeliveryM;

 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;

 GridDeliveryM->OnDrawColumnCell = this->GridDrawColumnCell;
 GridDeliveryM->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridDeliveryM->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridDeliveryM->OnTitleBtnClick = this->GridTitleBtnClick;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;
 
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::ToolAddClick(TObject *Sender)
{
 dataset->Insert();
 dataset->FieldByName("METHOD_ID")->AsInteger = 0;

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 GridDeliveryM->Options = GridDeliveryM->Options >> dgRowSelect;
 GridDeliveryM->Options = GridDeliveryM->Options << dgEditing;

 GridDeliveryM->SelectedIndex = 0;
 GridDeliveryM->SetFocus();
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::ToolDeleteClick(TObject *Sender)
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
void __fastcall TFrmManageDeliveryMeth::ToolEditClick(TObject *Sender)
{
 dataset->Edit();

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::ToolAcceptClick(TObject *Sender)
{
 if( 0 )
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά (κίτρινα) πεδία",ApplicationName, MB_ICONERROR);
  return;
 }

 dataset->Post();


 ToolRefreshClick(Sender);
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();

}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::DatasetDeliveryMBeforeEdit(
      TDataSet *DataSet)
{
 GridDeliveryM->Options = GridDeliveryM->Options >> dgRowSelect;
 GridDeliveryM->Options = GridDeliveryM->Options << dgEditing;
 GridDeliveryM->EditorMode = true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::DatasetDeliveryMAfterPost(
      TDataSet *DataSet)
{
 GridDeliveryM->Options = GridDeliveryM->Options >> dgEditing;
 GridDeliveryM->Options = GridDeliveryM->Options << dgRowSelect;

 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::DatasetDeliveryMBeforeInsert(
      TDataSet *DataSet)
{
 GridDeliveryM->Options = GridDeliveryM->Options >> dgRowSelect;
 GridDeliveryM->Options = GridDeliveryM->Options << dgEditing;
 GridDeliveryM->EditorMode = true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::DatasetDeliveryMAfterCancel(
      TDataSet *DataSet)
{
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDeliveryMeth::GridDeliveryMUserSort(
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

