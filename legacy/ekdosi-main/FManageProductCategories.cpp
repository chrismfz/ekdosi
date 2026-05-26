//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"

#include "FManageProductCategories.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManPrCateg *FrmManPrCateg;
//---------------------------------------------------------------------------
__fastcall TFrmManPrCateg::TFrmManPrCateg(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);

 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 GridCategories->Color = primary;
 GridCategories->AlternateRowColor = secondary;

 DatasetPrCategories->Database = database;
 DatasetPrCategories->Transaction = transaction;
 DatasetPrCategories->Active = true;

 //set main dataset
 dataset = DatasetPrCategories;

 GridCategories->OnDrawColumnCell = this->GridDrawColumnCell;
 GridCategories->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridCategories->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridCategories->OnTitleBtnClick = this->GridTitleBtnClick;

 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmManPrCateg::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);
 delete reg;	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManPrCateg::ToolAddClick(TObject *Sender)
{ 
 dataset->Insert();
 RollDetail->Collapsed = false;
 dataset->FieldByName("CAT_ID")->AsInteger = 0;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 editDescription->SetFocus();	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManPrCateg::ToolDeleteClick(TObject *Sender)
{
 if(dataset->RecordCount==0)
  return;

 try
 {
  dataset->Delete();
 }
 catch(Exception &e)
 {
  showMessage(e.Message.c_str(),ApplicationName,MB_ICONERROR);
 }	
}
//---------------------------------------------------------------------------
void __fastcall TFrmManPrCateg::ToolEditClick(TObject *Sender)
{
 dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
 editDescription->SetFocus();
}
//---------------------------------------------------------------------------
void __fastcall TFrmManPrCateg::ToolAcceptClick(TObject *Sender)
{
 if(editDescription->Text.Trim().Length() == 0  )
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
}
//---------------------------------------------------------------------------
void __fastcall TFrmManPrCateg::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}
//---------------------------------------------------------------------------


void __fastcall TFrmManPrCateg::GridCategoriesUserSort(TJvDBUltimGrid *Sender,
      TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK)
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
//-------------------------------------------------------------------------


