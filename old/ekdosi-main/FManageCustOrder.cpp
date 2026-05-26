//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FManageCustOrder.h"
#include "RegistryAccess.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmManageCustOrder *FrmManageCustOrder;
//---------------------------------------------------------------------------
__fastcall TFrmManageCustOrder::TFrmManageCustOrder(TComponent* Owner)
	: NewSpecialForm(Owner)
{
 RegAccess *reg = new RegAccess(this);

 reg->getColors(&primary, &secondary, &selectedColor);
 delete reg;

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 inspectOrder();

 dataset = DatasetCustOrder;

 DatasetCustOrder->Active = true;

 //Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;

 showRecordsFetched();
}
//---------------------------------------------------------------------------
void TFrmManageCustOrder::inspectOrder()
{
 TIBQuery *qry = new TIBQuery(this);

 qry->Database = database;
 qry->Transaction = transaction;
 qry->SQL->Text = "EXECUTE PROCEDURE INSPECT_CUST_ORDER";
 qry->ExecSQL();

 delete qry;
}
void __fastcall TFrmManageCustOrder::ToolButton4Click(TObject *Sender)
{
 TIBQuery *qry = new TIBQuery(this);

 qry->Database = database;
 qry->Transaction = transaction;

 qry->SQL->Text = "EXECUTE PROCEDURE SWAP_CUST_ORDER(:CUST_ID1, :CUST_ID2)";
 qry->ParamByName("CUST_ID1")->AsInteger = DatasetCustOrder->FieldByName("CUST_ID")->AsInteger;
 DatasetCustOrder->Next();
 qry->ParamByName("CUST_ID2")->AsInteger = DatasetCustOrder->FieldByName("CUST_ID")->AsInteger;
 qry->ExecSQL();

 ToolRefresh->Click();
 delete qry;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCustOrder::ToolButton1Click(TObject *Sender)
{
 TIBQuery *qry = new TIBQuery(this);

 qry->Database = database;
 qry->Transaction = transaction;

 qry->SQL->Text = "EXECUTE PROCEDURE SWAP_CUST_ORDER(:CUST_ID1, :CUST_ID2)";
 qry->ParamByName("CUST_ID1")->AsInteger = DatasetCustOrder->FieldByName("CUST_ID")->AsInteger;
 DatasetCustOrder->Prior();
 qry->ParamByName("CUST_ID2")->AsInteger = DatasetCustOrder->FieldByName("CUST_ID")->AsInteger;
 qry->ExecSQL();

 ToolRefresh->Click();
 delete qry;
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCustOrder::ViewProjectsCellClick(
      TcxCustomGridTableView *Sender,
      TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
      TShiftState AShift, bool &AHandled)
{
 this->Caption = dataset->FieldByName("NAME")->AsString;	
}
//---------------------------------------------------------------------------

void __fastcall TFrmManageCustOrder::DatasetCustOrderAfterScroll(
      TDataSet *DataSet)
{
 showRecordsFetched();
}
//---------------------------------------------------------------------------

