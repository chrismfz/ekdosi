//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FMetricUnits.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmMetricUnits *FrmMetricUnits;
//---------------------------------------------------------------------------
__fastcall TFrmMetricUnits::TFrmMetricUnits(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);
 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 GridMetricUnits->Color = primary;
 GridMetricUnits->AlternateRowColor = secondary;

 //Point to the main dataset
 dataset = DatasetMetricUnit;

 dataset->Database = database;
 dataset->Transaction = transaction;
 dataset->Active = true;

 //Grid Events
 GridMetricUnits->OnDrawColumnCell = this->GridDrawColumnCell;
 GridMetricUnits->OnMouseWheelUp = (TMouseWheelUpDownEvent )(&GridMouseWheelUp);
 GridMetricUnits->OnMouseWheelDown = (TMouseWheelUpDownEvent )(&GridMouseWheelDown);
 GridMetricUnits->OnTitleBtnClick = this->GridTitleBtnClick;
 GridMetricUnits->OnUserSort = this->GridUserSort;


 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;

 delete reg;
}
//---------------------------------------------------------------------------

bool TFrmMetricUnits::checkFields()
{
  bool error = true;
 
 if(DBEditName->Text.Length() == 0 )
  DBEditName->SetFocus();
 else
  error = false;

 if(error)
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά πεδία.",ApplicationName, MB_ICONERROR);
  return(false);
 }
 else
  return(true);
}

void __fastcall TFrmMetricUnits::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name,RollDetail->Collapsed);
 delete reg;
}
//---------------------------------------------------------------------------
void __fastcall TFrmMetricUnits::ToolDeleteClick(TObject *Sender)
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
void __fastcall TFrmMetricUnits::ToolEditClick(TObject *Sender)
{
dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;
 
 DBEditName->SetFocus();
 lblCheck->Visible = true;
}
//---------------------------------------------------------------------------
void __fastcall TFrmMetricUnits::ToolAcceptClick(TObject *Sender)
{
if( !checkFields() )
  return;

 dataset->Post();
 ToolRefreshClick(this);
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
 lblCheck->Visible = false;
 ToolRefreshClick(Sender);
}
//---------------------------------------------------------------------------
void __fastcall TFrmMetricUnits::ToolCancelClick(TObject *Sender)
{
dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
 lblCheck->Visible = false;
}

void __fastcall TFrmMetricUnits::ToolAddClick(TObject *Sender)
{
dataset->Insert();
 RollDetail->Collapsed = false;
 dataset->FieldByName("METRIC_ID")->AsInteger = 0;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 lblCheck->Visible = true;
 DBEditName->SetFocus();
}
//---------------------------------------------------------------------------

