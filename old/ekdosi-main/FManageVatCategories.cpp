//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"
#include "Fmain.h"


#include "FManageVatCategories.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageVatCategories *FrmManageVatCategories;
//---------------------------------------------------------------------------
__fastcall TFrmManageVatCategories::TFrmManageVatCategories(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);

 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 GridCategories->Color = primary;
 GridCategories->AlternateRowColor = secondary;

 //set main dataset
 dataset = DatasetVatCategories;

 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;

 GridCategories->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridCategories->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridCategories->OnTitleBtnClick = this->GridTitleBtnClick;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::GridCategoriesDrawColumnCell(
      TObject *Sender, const TRect &Rect, int DataCol, TColumn *Column,
      TGridDrawState State)
{
 if(State.Contains(Grids::gdSelected))
  ((TJvDBGrid *)Sender)->Canvas->Brush->Color = selectedColor;

 if(dataset->FieldByName("DEFAULT_CAT")->AsInteger == 1)
 {
  ((TJvDBGrid *)Sender)->Canvas->Brush->Color = TColor(0xFFFACD);
  if(State.Contains(Grids::gdSelected))
   ((TJvDBGrid *)Sender)->Canvas->Font->Color = clRed;
 }

 ((TJvDBGrid *)Sender)->DefaultDrawColumnCell(Rect, DataCol, Column, State);	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::FormCloseQuery(TObject *Sender,
      bool &CanClose)
{
 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);
 delete reg;		
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::DatasetVatCategoriesAfterScroll(
      TDataSet *DataSet)
{
 if(dataset->FieldByName("DEFAULT_CAT")->AsInteger == 1)
 {
  checkDefault->Checked = true;
  ToolAssignDefault->Enabled = false;
 }
 else
 {
  checkDefault->Checked = false;
  ToolAssignDefault->Enabled = true;
 }
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::ToolAddClick(TObject *Sender)
{
 dataset->Insert();
 RollDetail->Collapsed = false;
 dataset->FieldByName("VATCAT_ID")->AsInteger = 0;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 editDescription->SetFocus();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::ToolDeleteClick(TObject *Sender)
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
void __fastcall TFrmManageVatCategories::ToolEditClick(TObject *Sender)
{
 dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
 checkDefault->ReadOnly = false;
 editDescription->SetFocus();

}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
 checkDefault->ReadOnly = true;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::ToolAcceptClick(TObject *Sender)
{
 if( dataset->FieldByName("DESCRIPTION")->AsString.Length() == 0
	|| dataset->FieldByName("VALUE")->IsNull)
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά (κίτρινα) πεδία",ApplicationName, MB_ICONERROR);
  return;
 }

 if(checkDefault->Checked)
  dataset->FieldByName("DEFAULT_CAT")->AsInteger = 1;
 else
  dataset->FieldByName("DEFAULT_CAT")->AsInteger = 0;

 dataset->Post();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
 checkDefault->ReadOnly = true;

 ToolRefreshClick(Sender);
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::ToolAssignDefaultClick(TObject *Sender)
{
 dataset->Edit();
 dataset->FieldByName("DEFAULT_CAT")->AsInteger = 1;
 dataset->Post();

 ToolRefreshClick(Sender);
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::editValueExit(TObject *Sender)
{
 if(editValue->Text.Length() >0 && (editValue->Text.ToDouble() <0 || editValue->Text.ToDouble() >100) )
 {
  showMessage("Παρακαλώ εισάγεται ποσοστό επι τοις εκατό.",ApplicationName, MB_ICONERROR);
  editValue->SetFocus();
  editValue->SelectAll();
 }
}
//---------------------------------------------------------------------------
void __fastcall TFrmManageVatCategories::GridCategoriesUserSort(
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



