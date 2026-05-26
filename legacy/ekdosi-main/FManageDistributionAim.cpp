//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FManageDistributionAim.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageDistAim *FrmManageDistAim;
//---------------------------------------------------------------------------
__fastcall TFrmManageDistAim::TFrmManageDistAim(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);

 GridDistributionMethods->Color = primary;
 GridDistributionMethods->AlternateRowColor = secondary;

 //set main dataset
 dataset = DatasetDistAim;

 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;

 GridDistributionMethods->OnDrawColumnCell = this->GridDrawColumnCell;
 GridDistributionMethods->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridDistributionMethods->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridDistributionMethods->OnTitleBtnClick = this->GridTitleBtnClick;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;
 
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::ToolAddClick(TObject *Sender)
{
 dataset->Insert();
 dataset->FieldByName("DISTAIM_ID")->AsInteger = 0;

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 GridDistributionMethods->Options = GridDistributionMethods->Options >> dgRowSelect;
 GridDistributionMethods->Options = GridDistributionMethods->Options << dgEditing;

 GridDistributionMethods->SelectedIndex = 0;
 GridDistributionMethods->SetFocus();
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::ToolDeleteClick(TObject *Sender)
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
void __fastcall TFrmManageDistAim::ToolEditClick(TObject *Sender)
{
 dataset->Edit();

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::ToolAcceptClick(TObject *Sender)
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
void __fastcall TFrmManageDistAim::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::DatasetDistAimAfterCancel(TDataSet *DataSet)
{
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::DatasetDistAimAfterPost(TDataSet *DataSet)
{
 GridDistributionMethods->Options = GridDistributionMethods->Options >> dgEditing;
 GridDistributionMethods->Options = GridDistributionMethods->Options << dgRowSelect;

 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::DatasetDistAimBeforeEdit(TDataSet *DataSet)
{
 GridDistributionMethods->Options = GridDistributionMethods->Options >> dgRowSelect;
 GridDistributionMethods->Options = GridDistributionMethods->Options << dgEditing;
 GridDistributionMethods->EditorMode = true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::DatasetDistAimBeforeInsert(TDataSet *DataSet)
{
 GridDistributionMethods->Options = GridDistributionMethods->Options >> dgRowSelect;
 GridDistributionMethods->Options = GridDistributionMethods->Options << dgEditing;
 GridDistributionMethods->EditorMode = true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageDistAim::GridDistributionMethodsUserSort(
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
