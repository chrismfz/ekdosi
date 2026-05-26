//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop

#include "FShowMyData.h"
#include <xmldoc.hpp>
#include <msxmldom.hpp>
#include <System.RegularExpressions.hpp>
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "JvDotNetControls"
#pragma link "cxClasses"
#pragma link "cxControls"
#pragma link "cxCustomData"
#pragma link "cxData"
#pragma link "cxDataControllerConditionalFormattingRulesManagerDialog"
#pragma link "cxDataStorage"
#pragma link "cxDBData"
#pragma link "cxEdit"
#pragma link "cxFilter"
#pragma link "cxGraphics"
#pragma link "cxGrid"
#pragma link "cxGridCustomTableView"
#pragma link "cxGridCustomView"
#pragma link "cxGridDBTableView"
#pragma link "cxGridLevel"
#pragma link "cxGridTableView"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma link "cxNavigator"
#pragma link "cxStyles"
#pragma link "JvExExtCtrls"
#pragma link "JvExtComponent"
#pragma link "JvPanel"
#pragma link "JvRollOut"
#pragma link "JvEdit"
#pragma link "JvExStdCtrls"
#pragma link "JvMemo"
#pragma link "MemDS"
#pragma link "VirtualTable"
#pragma link "JvExGrids"
#pragma link "JvStringGrid"
#pragma resource "*.dfm"
TFrmShowMyData *FrmShowMyData;


//---------------------------------------------------------------------------
__fastcall TFrmShowMyData::TFrmShowMyData(TComponent* Owner)
	: NewSpecialForm(Owner)
{
		clear_tables();


}
//---------------------------------------------------------------------------


void __fastcall TFrmShowMyData::JvDotNetButton1Click(TObject *Sender)
{
	clear_tables();
	httpTrans->CustomHeaders["aade-user-id"] = getConfString("AadeUser");
	httpTrans->CustomHeaders["Ocp-Apim-Subscription-Key"] = getConfString("AadeKey");
	httpTrans->Get("https://mydata-dev.azure-api.net/RequestTransmittedDocs?mark=0");
}
//---------------------------------------------------------------------------
int TFrmShowMyData::genMarkId()
{
 TIBQuery *tmpQuery = new TIBQuery(this);

	tmpQuery->SQL->Add("SELECT GEN_ID(GEN_MARK_ID,1) FROM RDB$DATABASE;");
 tmpQuery->Database = database;
 tmpQuery->Transaction = transaction;
 tmpQuery->Active = true;

	int mark_id = tmpQuery->FieldByName("GEN_ID")->AsInteger;

	delete tmpQuery;

	return mark_id;
}
//---------------------------------------------------------------------------
/* Replaces decimal seperator (,) with (.) */
String TFrmShowMyData::replace_separator(String value)
{
	TRegEx regex;
	return regex.Replace(value, ",", ".");
}

void TFrmShowMyData::clear_tables()
{
		int row_count = invList->RowCount;
		for(int i = 0; i < row_count; i++)
		{
			invList->RemoveRow(i);
		}
		invList->Cells[0][0] = "MARK";
		invList->Cells[1][0] = "ΑΦΜ";
		invList->Cells[2][0] = "ΣΕΙΡΑ + Α/Α";
		invList->Cells[3][0] = "Ημ/νία";
		invList->Cells[4][0] = "Πληρωμή";
		invList->Cells[5][0] = "Ακυρωμένο από";


		int cancelled_row_count = cancelledInvList->RowCount;
		for(int j = 0; j < cancelled_row_count; j++)
		{
			cancelledInvList->RemoveRow(j);
		}
		cancelledInvList->Cells[0][0] = "ΜΑΡΚ";
		cancelledInvList->Cells[1][0] = "Ακυρώνει το";
		cancelledInvList->Cells[2][0] = "Ημ/νία ακύρωσης";
}
void __fastcall TFrmShowMyData::httpTransRequestCompleted(TObject * const Sender,
										IHTTPResponse * const AResponse)
{
		_di_IXMLDocument xml_response = LoadXMLData(AResponse->ContentAsString());
		int inv_count = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetCount();
//		showMessage(IntToStr(inv_count), "dbg", MB_OK);
//		invList->RowCount = inv_count + 1;

		for(int i = 0; i < inv_count ; i++)
		{
			invList->InsertRow(invList->RowCount);
			invList->Cells[0][invList->RowCount - 1] = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("mark")->GetText();
			invList->Cells[1][invList->RowCount - 1] = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("counterpart")->ChildNodes->GetNode("vatNumber")->GetText();
			invList->Cells[2][invList->RowCount - 1] = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("invoiceHeader")->ChildNodes->GetNode("series")->GetText() + xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("invoiceHeader")->ChildNodes->GetNode("aa")->GetText();
			invList->Cells[3][invList->RowCount - 1] = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("invoiceHeader")->ChildNodes->GetNode("issueDate")->GetText();
			invList->Cells[4][invList->RowCount - 1] = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("paymentMethods")->ChildNodes->GetNode("paymentMethodDetails")->ChildNodes->GetNode("amount")->GetText();
			invList->Cells[5][invList->RowCount - 1] = xml_response->DocumentElement->ChildNodes->GetNode("invoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("cancelledByMark")->GetText();
		}

		int cancel_inv_count = xml_response->DocumentElement->ChildNodes->GetNode("cancelledInvoicesDoc")->ChildNodes->GetCount();
//		ShowMessage(IntToStr(cancel_inv_count));
		for(int i = 0; i < cancel_inv_count; i++)
		{
			cancelledInvList->InsertRow(cancelledInvList->RowCount);
			cancelledInvList->Cells[0][cancelledInvList->RowCount-1] = xml_response->DocumentElement->ChildNodes->GetNode("cancelledInvoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("cancellationMark")->GetText();
			cancelledInvList->Cells[1][cancelledInvList->RowCount-1] = xml_response->DocumentElement->ChildNodes->GetNode("cancelledInvoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("invoiceMark")->GetText();
			cancelledInvList->Cells[2][cancelledInvList->RowCount-1] = xml_response->DocumentElement->ChildNodes->GetNode("cancelledInvoicesDoc")->ChildNodes->GetNode(i)->ChildNodes->GetNode("cancellationDate")->GetText();
		}

  delete xml_response;

}
//---------------------------------------------------------------------------



