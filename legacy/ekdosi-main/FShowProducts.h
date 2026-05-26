//---------------------------------------------------------------------------

#ifndef FShowProductsH
#define FShowProductsH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLookOut.hpp"
#include "JvPanel.hpp"
#include "JvRollOut.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <Mask.hpp>
#include <ToolWin.hpp>
#include "JvDBLookup.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExStdCtrls.hpp"
#include "JvRadioButton.hpp"
#include "JvComCtrls.hpp"
#include "cxClasses.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxEdit.hpp"
#include "cxFilter.hpp"
#include "cxGraphics.hpp"
#include "cxGrid.hpp"
#include "cxGridCustomTableView.hpp"
#include "cxGridCustomView.hpp"
#include "cxGridDBTableView.hpp"
#include "cxGridLevel.hpp"
#include "cxGridTableView.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxStyles.hpp"

#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
#include <IBX.IBQuery.hpp>
#include "cxNavigator.hpp"
#include "JvSplitter.hpp"

#include <vector>
#include "CEditBox.h"

#include "CNewSpecialForm.h"

using namespace std;
//---------------------------------------------------------------------------
class TFrmShowProducts : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetProduct;
	TImageList *ImageList1;
	TJvPanel *PanelMain;
	TJvPanel *PanelTop;
	TJvPanel *PanelSearch;
	TJvExpressButton *btnAdd;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvRollOut *RollDetail;
	TStatusBar *StatusBar1;
	TDataSource *DSProducts;
	TIntegerField *DatasetProductPRODUCT_ID;
	TIBStringField *DatasetProductBARCODE;
	TIBStringField *DatasetProductDESCRIPTION_SHORT;
	TIntegerField *DatasetProductCAT_ID;
	TIntegerField *DatasetProductVATCAT_ID;
	TIBBCDField *DatasetProductBUY_PRICE;
	TIBBCDField *DatasetProductSELL_PRICE;
	TIBBCDField *DatasetProductPRICE_WVAT;
	TDateField *DatasetProductDATE_INSERTED;
	TMemoField *DatasetProductDESCRIPTION;
	TJvDotNetDBEdit *editBuyPrice;
	TJvDotNetDBEdit *editDescription;
	TJvDotNetEdit *editMarkup;
	TJvDotNetDBEdit *editOccupation;
	TJvDotNetDBEdit *editPriceWVat;
	TJvDotNetDBEdit *editSellPrice;
	TJvDBLookupCombo *lookupVatCat;
	TJvDBLookupCombo *lookupProductCategory;
	TJvDotNetDBMemo *JvDotNetDBMemo1;
	TLabel *Label1;
	TLabel *Label10;
	TLabel *Label11;
	TLabel *Label12;
	TLabel *Label2;
	TLabel *Label3;
	TLabel *Label4;
	TLabel *Label5;
	TLabel *Label6;
	TIBQuery *QryVatCategories;
	TIntegerField *QryVatCategoriesVATCAT_ID;
	TIBStringField *QryVatCategoriesDESCRIPTION;
	TMemoField *QryVatCategoriesLONG_DESCRIPTION;
	TSmallintField *QryVatCategoriesDEFAULT_CAT;
	TDataSource *DSVatCategories;
	TIBQuery *QryPrCategories;
	TIntegerField *QryPrCategoriesCAT_ID;
	TIBStringField *QryPrCategoriesDESCRIPTION_SHORT;
	TMemoField *QryPrCategoriesDESCRIPTION;
	TDataSource *DSPrCategories;
	TIBStringField *DatasetProductPRCAT_DESCR;
	TLabel *Label7;
	TJvDotNetDBEdit *JvDotNetDBEdit1;
	TLabel *Label8;
	TJvDotNetDBEdit *JvDotNetDBEdit2;
	TJvDBLookupCombo *JvDBLookupCombo3;
	TLabel *Label9;
	TIBQuery *QryMetricUnits;
	TIntegerField *QryMetricUnitsMETRIC_ID;
	TIBStringField *QryMetricUnitsNAME;
	TMemoField *QryMetricUnitsNOTES;
	TDataSource *DSMetricUnits;
	TIntegerField *DatasetProductMETRIC_ID;
	TIBBCDField *DatasetProductRESERVE;
	TIBBCDField *DatasetProductRESERVE_SECURE;
	TIBBCDField *QryVatCategoriesVALUE;
	TJvExpressButton *JvExpressButton1;
	TJvPanel *PanelQtyValues;
	TJvToolBar *JvToolBar2;
	TToolButton *ToolPAdd;
	TToolButton *ToolPDelete;
	TToolButton *ToolButton9;
	TToolButton *ToolPEdit;
	TToolButton *ToolPAccept;
	TToolButton *ToolPCancel;
	TToolButton *ToolButton13;
	TToolButton *ToolPRefresh;
	TJvDBUltimGrid *GridPricePerQty;
	TIBDataSet *DatasetPricePerQty;
	TDataSource *DSPricePerQty;
	TIntegerField *DatasetPricePerQtyPROD_PRICE_ID;
	TIntegerField *DatasetPricePerQtyPRODUCT_ID;
	TIBBCDField *DatasetPricePerQtyVAL;
	TIBBCDField *DatasetPricePerQtyDISCOUNT_PERCENT;
	TIBBCDField *DatasetPricePerQtyQTY;
	TJvPageControl *PageControl;
	TTabSheet *TabSheet1;
	TTabSheet *TabSheet2;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TcxGrid *GridSalesOnProducts;
	TcxGridDBTableView *ViewSalesOnProducts;
	TcxGridLevel *GridLevelCustInvoices;
	TJvPanel *PanelSales;
	TIBQuery *QuerySales;
	TDataSource *DSSales;
	TIntegerField *QuerySalesCUST_ID;
	TIntegerField *QuerySalesPRODUCT_ID;
	TIBBCDField *QuerySalesSUM;
	TDateField *QuerySalesINVDATE;
	TIBStringField *QuerySalesNAME;
	TcxGridDBColumn *ViewSalesOnProductsSUM;
	TcxGridDBColumn *ViewSalesOnProductsINVDATE;
	TcxGridDBColumn *ViewSalesOnProductsNAME;
	TIBBCDField *QuerySalesPRICE_PER_ITEM;
	TcxGridDBColumn *ViewSalesOnProductsPRICE_PER_ITEM;
	TJvSplitter *JvSplitter1;
	TIntegerField *QuerySalesINVOICE_ID;
	TcxGrid *GridCustomers;
	TcxGridDBTableView *ViewProduct;
	TcxGridLevel *GridCustomersLevel1;
	TcxGridDBColumn *ViewProductPRODUCT_ID;
	TcxGridDBColumn *ViewProductBARCODE;
	TcxGridDBColumn *ViewProductDESCRIPTION_SHORT;
	TcxGridDBColumn *ViewProductSELL_PRICE;
	TcxGridDBColumn *ViewProductPRICE_WVAT;
	TcxGridDBColumn *ViewProductPRCAT_DESCR;
	TcxGridDBColumn *ViewProductRESERVE;
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall GridProductsUserSort(TJvDBUltimGrid *Sender,
          TSortFields &FieldsToSort, AnsiString SortString, bool &SortOK);
	void __fastcall editBuyPriceExit(TObject *Sender);
	void __fastcall editMarkupExit(TObject *Sender);
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall lookupVatCatChange(TObject *Sender);
	void __fastcall editPriceWVatExit(TObject *Sender);
	void __fastcall editSellPriceExit(TObject *Sender);
	void __fastcall DatasetProductAfterScroll(TDataSet *DataSet);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall PanelQtyValuesExit(TObject *Sender);
	void __fastcall JvExpressButton1Click(TObject *Sender);
	void __fastcall PanelQtyValuesEnter(TObject *Sender);
	void __fastcall DatasetPricePerQtyAfterCancel(TDataSet *DataSet);
	void __fastcall DatasetPricePerQtyAfterEdit(TDataSet *DataSet);
	void __fastcall DatasetPricePerQtyAfterPost(TDataSet *DataSet);
	void __fastcall ToolPAddClick(TObject *Sender);
	void __fastcall ToolPDeleteClick(TObject *Sender);
	void __fastcall ToolPEditClick(TObject *Sender);
	void __fastcall ToolPAcceptClick(TObject *Sender);
	void __fastcall ToolPCancelClick(TObject *Sender);
	void __fastcall DatasetPricePerQtyAfterInsert(TDataSet *DataSet);
	void __fastcall ToolPRefreshClick(TObject *Sender);
	void __fastcall ViewSalesOnProductsCellDblClick(TcxCustomGridTableView *Sender,
          TcxGridTableDataCellViewInfo *ACellViewInfo, TMouseButton AButton,
          TShiftState AShift, bool &AHandled);
private:	// User declarations
	vector<EditBox *>  editBoxes;
	vector<EditBox *> garbage;
	TStringList *defaultSQL;
	void __fastcall btnMinusClick(TObject *Sender);
	void __fastcall editSearchChange(TObject *Sender);
	void calcSaleWVat(); //Υπολόγισε Τιμή με ΦΠΑ
	void calcSalePrice();// Υπολόγισε τιμή πώλησης χωρίς ΦΠΑ
	void calcVatToSale(); //Υπολόγισε τιμή
	bool checkDeps();
public:		// User declarations
	__fastcall TFrmShowProducts(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowProducts *FrmShowProducts;
//---------------------------------------------------------------------------
#endif
