// ---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FShowProducts.h"
#include "FMain.h"
#include "CEditBox.h"
#include "RegistryAccess.h"
#include "FEditInvoice.h"
// ---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "cxNavigator"
#pragma link "JvSplitter"
#pragma resource "*.dfm"
TFrmShowProducts *FrmShowProducts;

// ---------------------------------------------------------------------------
__fastcall TFrmShowProducts::TFrmShowProducts(TComponent* Owner) : NewSpecialForm(Owner)
{
 btnAdd->Left = PanelSearch->Width - 2 - btnAdd->Width;

 RegAccess *reg = new RegAccess(this);

 RollDetail->Collapsed = reg->loadCustomBool(RollDetail->Name);
 PanelSales->Width = reg->loadCustomBool("PanelSalesWidth");
 if(PanelSales->Width == 0)
  PanelSales->Width = 200;

 GridPricePerQty->Color = primary;
 GridPricePerQty->AlternateRowColor = secondary;

 StyleEven->Color = primary;
 StyleOdd->Color = secondary;
 StyleMain->TextColor = selectedColor;

 DatasetProduct->Active = true;
 QryVatCategories->Active = true;
 QryPrCategories->Active = true;
 QuerySales->Open();
 QryMetricUnits->Active = true;
 // Point to the main dataset
 dataset = DatasetProduct;

 defaultSQL = new TStringList();

 defaultSQL->AddStrings(dataset->SelectSQL);

 EditBox *box = new EditBox(PanelSearch, SEARCH_HORZ_SPC, (editBoxes.size() * SEARCH_SPC_BTWN) + SEARCH_VERTL_SPC, ImageList1, 10);

 box->setClickEvent(btnMinusClick);
 box->setTextChangedEvent(editSearchChange);
 box->setField("PRODUCT.DESCRIPTION_SHORT BARCODE", "Περιγραφή:");
 box->setWidth(PanelSearch->Width);
 editBoxes.push_back(box);

 GridPricePerQty->OnDrawColumnCell = this->GridDrawColumnCell;
 GridPricePerQty->OnMouseWheelUp = (TMouseWheelUpDownEvent)(&GridMouseWheelUp);
 GridPricePerQty->OnMouseWheelDown = (TMouseWheelUpDownEvent)(&GridMouseWheelDown);
 GridPricePerQty->OnTitleBtnClick = this->GridTitleBtnClick;

 // Tollbuttons events
 ToolPrevious->OnClick = ToolPreviousClick;
 ToolNext->OnClick = ToolNextClick;
 ToolRefresh->OnClick = ToolRefreshClick;

 // Set the height of the search panel
 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;

 PageControl->ActivePageIndex = 0;

 delete reg;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::btnMinusClick(TObject *Sender)
{
 unsigned int pos = ((((TWinControl*)Sender)->Top - SEARCH_VERTL_SPC) / SEARCH_SPC_BTWN);
 bool refreshDataset = false;

 vector<EditBox*>::iterator w;
 w = editBoxes.begin();

 for (int i = 0; i < pos; i++)
  w++;

 if ((*w)->getFilter().Length() > 0)
  refreshDataset = true;

 EditBox *tmpBox = *w;
 tmpBox->Hide();
 editBoxes.erase(w);
 garbage.push_back(tmpBox);

 if (refreshDataset)
  editSearchChange(Sender);

 if (editBoxes.size() == 0)
  return;

 w = editBoxes.begin();
 unsigned int i = 0;
 while (w != editBoxes.end())
 {
  (*w)->setTop((i * SEARCH_SPC_BTWN) + SEARCH_VERTL_SPC);
  i++;
  w++;
 }

 PanelTop->Height = (editBoxes.size() * SEARCH_SPC_BTWN) + 8;

}

void __fastcall TFrmShowProducts::editSearchChange(TObject *Sender)
{
 vector<EditBox*>::iterator w;

 TStringList *OrderByList = new TStringList();

 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count - 2]);
 OrderByList->Add(dataset->SelectSQL->Strings[dataset->SelectSQL->Count - 1]);

 dataset->SelectSQL->Clear();
 for (int i = 0; i < defaultSQL->Count - 2; i++)
  dataset->SelectSQL->Add(defaultSQL->Strings[i]);

 w = editBoxes.begin();
 dataset->SelectSQL->Add("WHERE");
 while (w != editBoxes.end())
 {
  if ((*w)->getFilter().Length() > 0)
   dataset->SelectSQL->Add((*w)->getFilter());
  else
  {
   w++;
   continue;
  }
  w++;
  if (w != editBoxes.end())
   dataset->SelectSQL->Add("AND");
 }

 if (dataset->SelectSQL->Count > 0 && (dataset->SelectSQL->Strings[dataset->SelectSQL->Count - 1] == AnsiString("WHERE")
   || dataset->SelectSQL->Strings[dataset->SelectSQL->Count - 1] == "AND"))
  dataset->SelectSQL->Delete(dataset->SelectSQL->Count - 1);

 dataset->SelectSQL->Add(OrderByList->Strings[0]);
 dataset->SelectSQL->Add(OrderByList->Strings[1]);
 // debuging
 // Memo1->Lines = DatasetCustomer->SelectSQL;
 dataset->Active = false;
 dataset->Active = true;

 delete OrderByList;
}

void __fastcall TFrmShowProducts::FormCloseQuery(TObject *Sender, bool &CanClose)
{
 vector<EditBox*>::iterator w;
 w = garbage.begin();

 while (w != garbage.end())
 {
  delete(*w);
  w++;
 }

 w = editBoxes.begin();
 while (w != editBoxes.end())
 {
  delete(*w);
  w++;
 }

 RegAccess *reg = new RegAccess(this);

 reg->saveCustomBool(RollDetail->Name, RollDetail->Collapsed);
 reg->saveCustomBool("PanelSalesWidth",PanelSales->Width);
 delete reg;
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::GridProductsUserSort(TJvDBUltimGrid *Sender, TSortFields &FieldsToSort, AnsiString SortString,
 bool &SortOK)
{
 for (int i = 1; i <= SortString.Length(); i++)
  if (SortString[i] == '[' || SortString[i] == ']')
   SortString[i] = ' ';

 // How exceptions are handled -- this is for joined field
 if (SortString.Trim().SubString(1, 11) == "PRCAT_DESCR")
  SortString = "PRODUCT_CATEGORIES.DESCRIPTION_SHORT";
 else if (SortString.Trim().SubString(1, 11) == "DESCRIPTION_SHORT")
  SortString = "PRODUCT." + SortString.Trim();

 try
 {
  dataset->SelectSQL->Strings[dataset->SelectSQL->Count - 1] = SortString;
  dataset->Active = true;
 }
 catch(Exception & e)
 {
  showMessage(AnsiString(e.Message).c_str(), ApplicationName, MB_ICONERROR);
  return;
 }

 SortOK = true;
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::editBuyPriceExit(TObject *Sender)
{
 calcSalePrice();
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::editMarkupExit(TObject *Sender)
{
 if (editMarkup->Text.Length() == 0)
  editMarkup->Text = "0";

 if (editMarkup->Text.ToInt() < 0 || editMarkup->Text.ToInt() > 100)
 {
  showMessage("Παρακαλώ εισάγεται ποσοστό επι τοις εκατό.", ApplicationName, MB_ICONERROR);
  editMarkup->SetFocus();
  editMarkup->SelectAll();
 }
 calcSalePrice();

 CalcCursorPos();
}
// ---------------------------------------------------------------------------

void TFrmShowProducts::calcSaleWVat()
{
 if ((dataset->State == dsEdit || dataset->State == dsInsert) && !dataset->FieldByName("SELL_PRICE")
  ->IsNull && !QryVatCategories->FieldByName("VALUE")->IsNull)
 {
  double vat = 1 + (QryVatCategories->FieldByName("VALUE")->AsFloat / 100.00);
  dataset->FieldByName("PRICE_WVAT")->AsFloat = dataset->FieldByName("SELL_PRICE")->AsFloat * vat;
 }
}

void TFrmShowProducts::calcSalePrice()
{
 if ((dataset->State == dsEdit || dataset->State == dsInsert) && !dataset->FieldByName("BUY_PRICE")->IsNull && editMarkup->Text.Trim()
  .Length() > 0)
 {
  double buyPrice = 0;
  double markup = 0;

  try
  {
   buyPrice = dataset->FieldByName("BUY_PRICE")->AsFloat;
   markup = 1.00 + ((double)editMarkup->Text.ToInt() / 100.00);
  }
  catch(Exception & e)
  {;
  }

  dataset->FieldByName("SELL_PRICE")->AsFloat = buyPrice * markup;
 }

 calcSaleWVat();
}

// Υπολόγισε την τιμή μαζί με το ΦΠΑ
void TFrmShowProducts::calcVatToSale()
{
 if ((dataset->State == dsEdit || dataset->State == dsInsert) && !dataset->FieldByName("PRICE_WVAT")
  ->IsNull && !QryVatCategories->FieldByName("VALUE")->IsNull)
 {
  double markup = 1.00 + (QryVatCategories->FieldByName("VALUE")->AsFloat / 100.00);
  dataset->FieldByName("SELL_PRICE")->AsFloat = dataset->FieldByName("PRICE_WVAT")->AsFloat / markup;
 }
}

bool TFrmShowProducts::checkDeps()
{
 if (dataset->FieldByName("DESCRIPTION_SHORT")->AsString.Length() == 0 || dataset->FieldByName("CAT_ID")->IsNull || dataset->FieldByName
  ("VATCAT_ID")->IsNull)
 {
  showMessage("Συμπληρώστε τα υποχρεωτικά πεδία.", ApplicationName, MB_ICONERROR);
  return(false);
 }
 return(true);

}

void __fastcall TFrmShowProducts::ToolAddClick(TObject *Sender)
{

 RollDetail->Collapsed = false;
 editDescription->SetFocus();
 dataset->Insert();
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 RegAccess *reg = new RegAccess(this);
 editMarkup->Text = AnsiString(reg->getAppParameterInt("MarkupPercent"));
 delete reg;

 QryVatCategories->Last();
 int recordCount = QryVatCategories->RecordCount;
 QryVatCategories->First();

 if (QryVatCategories->FieldByName("DEFAULT_CAT")->AsInteger == 1)
 {
  DatasetProduct->FieldByName("VATCAT_ID")->AsInteger = QryVatCategories->FieldByName("VATCAT_ID")->AsInteger;
  return;
 }
 while (QryVatCategories->RecNo < recordCount)
 {
  QryVatCategories->Next();
  if (QryVatCategories->FieldByName("DEFAULT_CAT")->AsInteger == 1)
  {
   DatasetProduct->FieldByName("VATCAT_ID")->AsInteger = QryVatCategories->FieldByName("VATCAT_ID")->AsInteger;
   break;
  }
 }

 editDescription->SetFocus();
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::ToolDeleteClick(TObject *Sender)
{
 if (dataset->RecordCount == 0)
  return;

 try
 {
  dataset->Delete();
 }
 catch(Exception & e)
 {
  showMessage(e.Message.c_str(), ApplicationName, MB_ICONERROR);
 }
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::ToolEditClick(TObject *Sender)
{
 dataset->Edit();
 RollDetail->Collapsed = false;
 ToolAccept->Enabled = true;
 ToolCancel->Enabled = true;
 ToolEdit->Enabled = false;
 ToolAdd->Enabled = false;
 ToolDelete->Enabled = false;

 editDescription->SetFocus();

 RegAccess *reg = new RegAccess(this);
 editMarkup->Text = AnsiString(reg->getAppParameterInt("MarkupPercent"));
 delete reg;
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::ToolAcceptClick(TObject *Sender)
{
 if (!checkDeps())
  return;

 dataset->Post();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::ToolCancelClick(TObject *Sender)
{
 dataset->Cancel();
 ToolAccept->Enabled = false;
 ToolCancel->Enabled = false;
 ToolAdd->Enabled = true;
 ToolDelete->Enabled = true;
 ToolEdit->Enabled = true;
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::lookupVatCatChange(TObject *Sender)
{
 calcSaleWVat();
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::editPriceWVatExit(TObject *Sender)
{
 calcVatToSale();
}

// ---------------------------------------------------------------------------
void __fastcall TFrmShowProducts::editSellPriceExit(TObject *Sender)
{
 calcSaleWVat();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::DatasetProductAfterScroll(TDataSet *DataSet)
{
 showRecordsFetched();

 DatasetPricePerQty->Close();
 DatasetPricePerQty->ParamByName("PRODUCT_ID")->AsInteger = DatasetProduct->FieldByName("PRODUCT_ID")->AsInteger;
 DatasetPricePerQty->Open();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::FormShow(TObject *Sender)
{
 showRecordsFetched();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::PanelQtyValuesExit(TObject *Sender)
{
 PanelQtyValues->Hide();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::JvExpressButton1Click(TObject *Sender)
{
 if (!PanelQtyValues->Visible)
 {
  PanelQtyValues->Show();
  GridPricePerQty->SetFocus();
 }
 else
  PanelQtyValues->Hide();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::PanelQtyValuesEnter(TObject *Sender)
{
 GridPricePerQty->SetFocus();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::DatasetPricePerQtyAfterCancel(TDataSet *DataSet)
{
 ToolPAccept->Enabled = false;
 ToolPCancel->Enabled = false;
 ToolPAdd->Enabled = true;
 ToolPDelete->Enabled = true;
 ToolPEdit->Enabled = true;
 GridPricePerQty->Options = GridPricePerQty->Options >> dgEditing;
 GridPricePerQty->Options = GridPricePerQty->Options << dgRowSelect;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::DatasetPricePerQtyAfterEdit(TDataSet *DataSet)
{
 GridPricePerQty->Options = GridPricePerQty->Options >> dgRowSelect;
 GridPricePerQty->Options = GridPricePerQty->Options << dgEditing;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::DatasetPricePerQtyAfterPost(TDataSet *DataSet)
{
 ToolPAccept->Enabled = false;
 ToolPCancel->Enabled = false;
 ToolPAdd->Enabled = true;
 ToolPDelete->Enabled = true;
 ToolPEdit->Enabled = true;

 // 8 ToolSRefreshClick(Sender);
 GridPricePerQty->Options = GridPricePerQty->Options >> dgEditing;
 GridPricePerQty->Options = GridPricePerQty->Options << dgRowSelect;

 DatasetPricePerQty->Refresh();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::ToolPAddClick(TObject *Sender)
{
 DatasetPricePerQty->Append();
 DatasetPricePerQty->FieldByName("PRODUCT_ID")->AsInteger = DatasetProduct->FieldByName("PRODUCT_ID")->AsInteger;
 ToolPAccept->Enabled = true;
 ToolPCancel->Enabled = true;
 ToolPEdit->Enabled = false;
 ToolPAdd->Enabled = false;
 ToolPDelete->Enabled = false;

 GridPricePerQty->SetFocus();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::ToolPDeleteClick(TObject *Sender)
{
 if (DatasetPricePerQty->RecordCount == 0)
  return;

 try
 {
  DatasetPricePerQty->Delete();
 }
 catch(Exception & e)
 {
  showMessage(e.Message.c_str(), ApplicationName, MB_ICONERROR);
 }
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::ToolPEditClick(TObject *Sender)
{
 DatasetPricePerQty->Edit();

 ToolPAccept->Enabled = true;
 ToolPCancel->Enabled = true;
 ToolPEdit->Enabled = false;
 ToolPAdd->Enabled = false;
 ToolPDelete->Enabled = false;
 GridPricePerQty->SetFocus();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::ToolPAcceptClick(TObject *Sender)
{
 DatasetPricePerQty->Post();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::ToolPCancelClick(TObject *Sender)
{
 DatasetPricePerQty->Cancel();
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::DatasetPricePerQtyAfterInsert(TDataSet *DataSet)
{
 GridPricePerQty->Options = GridPricePerQty->Options >> dgRowSelect;
 GridPricePerQty->Options = GridPricePerQty->Options << dgEditing;
}
// ---------------------------------------------------------------------------

void __fastcall TFrmShowProducts::ToolPRefreshClick(TObject *Sender)
{
 TByteDynArray bookmark;
 bookmark = DatasetPricePerQty->GetBookmark();
 try
 {
  DatasetPricePerQty->Active = false;
  DatasetPricePerQty->Active = true;
 }
 catch(Exception & e)
 {;
 }

 DatasetPricePerQty->GotoBookmark(bookmark);
}
// ---------------------------------------------------------------------------


void __fastcall TFrmShowProducts::ViewSalesOnProductsCellDblClick(TcxCustomGridTableView *Sender,
          TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
          TShiftState AShift, bool &AHandled)
{
  TFrmEditInvoice *frmEditInvoice = new TFrmEditInvoice(Owner,QuerySales->FieldByName("INVOICE_ID")->AsInteger);
}
//---------------------------------------------------------------------------

