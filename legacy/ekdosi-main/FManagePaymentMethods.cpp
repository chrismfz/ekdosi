//---------------------------------------------------------------------------
#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FManagePaymentMethods.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManagePaymentMeth *FrmManagePaymentMeth;
//---------------------------------------------------------------------------
__fastcall TFrmManagePaymentMeth::TFrmManagePaymentMeth(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 
// RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 GridPaymentM->Color = primary;
 GridPaymentM->AlternateRowColor = secondary;

 //set main dataset
 dataset = DatasetPaymentM;

 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;



 GridPaymentM->OnDrawColumnCell = this->GridDrawColumnCell;
 GridPaymentM->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridPaymentM->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridPaymentM->OnTitleBtnClick = this->GridTitleBtnClick;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;
 
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManagePaymentMeth::ToolAddClick(TObject *Sender)
{
 dataset->Insert();
 dataset->FieldByName("METHOD_ID")->AsInteger = 0;

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 GridPaymentM->Options = GridPaymentM->Options >> dgRowSelect;
 GridPaymentM->Options = GridPaymentM->Options << dgEditing;

 GridPaymentM->SelectedIndex = 0;
 GridPaymentM->SetFocus();
}
//---------------------------------------------------------------------------


void __fastcall TFrmManagePaymentMeth::GridPaymentMDrawColumnCell(
      TObject *Sender, const TRect &Rect, int DataCol, TColumn *Column,
      TGridDrawState State)
{
 if(State.Contains(Grids::gdSelected))
  ((TJvDBGrid *)Sender)->Canvas->Brush->Color = selectedColor;



 if(Column->FieldName == "TMPTYPE")
 {
  RECT RText = static_cast<RECT>(Rect);
  TCanvas *SGCanvas = ((TJvDBGrid *)Sender)->Canvas;
  SGCanvas->Font->Color = clBlack;

 SGCanvas->FillRect(Rect);

 // ((TJvDBGrid *)Sender)->Canvas->TextRect(Rect,10,10,"HELLO");
// DrawText(SGCanvas, "HELLO",5, &RText, DT_LEFT |DT_VCENTER);


 //((TJvDBGrid *)Sender)->DefaultDrawColumnCell(RText, DataCol, Column, State);
  //Column->Field->AsString = ComboPayType->Items->Strings[DatasetPaymentM->FieldByName("TYPE")->AsInteger];
 }
 else
   ((TJvDBGrid *)Sender)->DefaultDrawColumnCell(Rect, DataCol, Column, State);

}
//---------------------------------------------------------------------------



void __fastcall TFrmManagePaymentMeth::ToolDeleteClick(TObject *Sender)
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

void __fastcall TFrmManagePaymentMeth::ToolEditClick(TObject *Sender)
{
 dataset->Edit();

 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 GridPaymentM->Options = GridPaymentM->Options >> dgRowSelect;
 GridPaymentM->Options = GridPaymentM->Options << dgEditing;

 GridPaymentM->SetFocus();
}
//---------------------------------------------------------------------------

void __fastcall TFrmManagePaymentMeth::ToolAcceptClick(TObject *Sender)
{
 if( dataset->FieldByName("DESCRIPTION")->AsString.Length() == 0
	|| dataset->FieldByName("DUE_DAYS")->IsNull)
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

void __fastcall TFrmManagePaymentMeth::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManagePaymentMeth::DatasetPaymentMAfterPost(
      TDataSet *DataSet)
{
 GridPaymentM->Options = GridPaymentM->Options << dgRowSelect;

 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManagePaymentMeth::DatasetPaymentMAfterCancel(
	  TDataSet *DataSet)
{
 GridPaymentM->Options = GridPaymentM->Options << dgRowSelect;

 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;	
}
//---------------------------------------------------------------------------


void __fastcall TFrmManagePaymentMeth::GridPaymentMUserSort(
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

