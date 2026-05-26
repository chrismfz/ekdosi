//---------------------------------------------------------------------------

#ifndef FAddInvoiceH
#define FAddInvoiceH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBLookup.hpp"
#include "JvExControls.hpp"
#include <DB.hpp>
#include "JvDBDotNetControls.hpp"
#include <DBCtrls.hpp>
#include <Mask.hpp>
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include <ExtCtrls.hpp>
#include "JvDBControls.hpp"
#include "JvExMask.hpp"
#include "JvToolEdit.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExStdCtrls.hpp"
#include "JvLinkLabel.hpp"
#include "JvBehaviorLabel.hpp"
#include "JvLabel.hpp"
#include <ComCtrls.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExDBGrids.hpp"
#include <DBGrids.hpp>
#include <Grids.hpp>
#include "JvLookOut.hpp"
#include "JvMaskEdit.hpp"
#include "JvSpin.hpp"
#include "JvMenus.hpp"
#include <Menus.hpp>
#include "JvRadioButton.hpp"

#include "CNewSpecialForm.h"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxDBEdit.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxTextEdit.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include "cxCalendar.hpp"
#include "cxDBLookupComboBox.hpp"
#include "cxDBLookupEdit.hpp"
#include "cxDropDownEdit.hpp"
#include "cxLookupEdit.hpp"
#include "cxMaskEdit.hpp"
#include "cxMemo.hpp"
#include "dxSkinBlack.hpp"
#include "dxSkinBlue.hpp"
#include "dxSkinBlueprint.hpp"
#include "dxSkinCaramel.hpp"
#include "dxSkinCoffee.hpp"
#include "dxSkinDarkRoom.hpp"
#include "dxSkinDarkSide.hpp"
#include "dxSkinDevExpressDarkStyle.hpp"
#include "dxSkinDevExpressStyle.hpp"
#include "dxSkinFoggy.hpp"
#include "dxSkinGlassOceans.hpp"
#include "dxSkinHighContrast.hpp"
#include "dxSkiniMaginary.hpp"
#include "dxSkinLilian.hpp"
#include "dxSkinLiquidSky.hpp"
#include "dxSkinLondonLiquidSky.hpp"
#include "dxSkinMcSkin.hpp"
#include "dxSkinMetropolis.hpp"
#include "dxSkinMetropolisDark.hpp"
#include "dxSkinMoneyTwins.hpp"
#include "dxSkinOffice2007Black.hpp"
#include "dxSkinOffice2007Blue.hpp"
#include "dxSkinOffice2007Green.hpp"
#include "dxSkinOffice2007Pink.hpp"
#include "dxSkinOffice2007Silver.hpp"
#include "dxSkinOffice2010Black.hpp"
#include "dxSkinOffice2010Blue.hpp"
#include "dxSkinOffice2010Silver.hpp"
#include "dxSkinOffice2013DarkGray.hpp"
#include "dxSkinOffice2013LightGray.hpp"
#include "dxSkinOffice2013White.hpp"
#include "dxSkinOffice2016Colorful.hpp"
#include "dxSkinOffice2016Dark.hpp"
#include "dxSkinPumpkin.hpp"
#include "dxSkinsCore.hpp"
#include "dxSkinsDefaultPainters.hpp"
#include "dxSkinSeven.hpp"
#include "dxSkinSevenClassic.hpp"
#include "dxSkinSharp.hpp"
#include "dxSkinSharpPlus.hpp"
#include "dxSkinSilver.hpp"
#include "dxSkinSpringTime.hpp"
#include "dxSkinStardust.hpp"
#include "dxSkinSummer2008.hpp"
#include "dxSkinTheAsphaltWorld.hpp"
#include "dxSkinTheBezier.hpp"
#include "dxSkinValentine.hpp"
#include "dxSkinVisualStudio2013Blue.hpp"
#include "dxSkinVisualStudio2013Dark.hpp"
#include "dxSkinVisualStudio2013Light.hpp"
#include "dxSkinVS2010.hpp"
#include "dxSkinWhiteprint.hpp"
#include "dxSkinXmas2008Blue.hpp"
#include "cxCheckBox.hpp"

typedef void (__closure *ptrSetInvId)(int);
//---------------------------------------------------------------------------
class TFrmAddInvoice : public NewSpecialForm
{
__published:	// IDE-managed Components
	TDataSource *DSInvTypes;
	TIBQuery *QryInvTypes;
	TLabel *Label5;
	TIBStringField *QryInvTypesINVTYPE_ID;
	TIBStringField *QryInvTypesNAME;
	TIBDataSet *DatasetInvoice;
	TDataSource *DSInvoice;
	TIBStringField *DatasetInvoiceINVCODE;
	TIntegerField *DatasetInvoiceCUST_ID;
	TIBStringField *DatasetInvoiceINVTYPE;
	TDateField *DatasetInvoiceINVDATE;
	TLabel *Label2;
	TLabel *Label1;
	TLabel *Label3;
	TJvPanel *PanelDetails;
	TLabel *Label4;
	TIntegerField *DatasetInvoiceINVOICE_ID;
	TIntegerField *DatasetInvoicePAID;
	TDateField *DatasetInvoiceDELIVERYDATE;
	TLabel *Label6;
	TIBQuery *QrySelCustomer;
	TIntegerField *QrySelCustomerCUST_ID;
	TIBStringField *QrySelCustomerAFM;
	TIBStringField *QrySelCustomerNAME;
	TIBStringField *QrySelCustomerADDRESS1;
	TIBStringField *QrySelCustomerADDRESS2;
	TIBStringField *QrySelCustomerCITY;
	TIBStringField *QrySelCustomerPOSTCODE;
	TIBStringField *QrySelCustomerPHONE1;
	TIBStringField *QrySelCustomerPHONE2;
	TIBStringField *QrySelCustomerFAX;
	TIBStringField *QrySelCustomerOCCUPATION;
	TIBStringField *QrySelCustomerTAXOFFICE;
	TMemoField *QrySelCustomerDETAILS;
	TLabel *Label7;
	TStatusBar *StatusBar;
	TLabel *Label8;
	TDataSource *DSDistAim;
	TIBQuery *QryDistAim;
	TIntegerField *DatasetInvoiceDISTRAIM_ID;
	TIntegerField *QryDistAimDISTAIM_ID;
	TIBStringField *QryDistAimDESCRIPTION;
	TLabel *Label9;
	TIBQuery *QryDeliveryMethod;
	TDataSource *DSDeliveryMethod;
	TIntegerField *DatasetInvoiceDELMETHOD_ID;
	TIntegerField *QryDeliveryMethodMETHOD_ID;
	TIBStringField *QryDeliveryMethodDESCRIPTION;
	TLabel *Label10;
	TIBQuery *QryPaymentMeth;
	TDataSource *DSPaymentMeth;
	TIntegerField *DatasetInvoicePAYMETH_ID;
	TIntegerField *QryPaymentMethMETHOD_ID;
	TIBStringField *QryPaymentMethDESCRIPTION;
	TJvPanel *PanelInvLines;
	TIBDataSet *DatasetInvLines;
	TDataSource *DSInvLines;
	TJvDBUltimGrid *GridInvoiceLines;
	TIBQuery *QrySelProducts;
	TJvPanel *PanelButtons;
	TIntegerField *QrySelProductsPRODUCT_ID;
	TIBStringField *QrySelProductsBARCODE;
	TIBStringField *QrySelProductsDESCRIPTION_SHORT;
	TIntegerField *QrySelProductsCAT_ID;
	TIntegerField *QrySelProductsVATCAT_ID;
	TIBBCDField *QrySelProductsBUY_PRICE;
	TIBBCDField *QrySelProductsSELL_PRICE;
	TIBBCDField *QrySelProductsPRICE_WVAT;
	TDateField *QrySelProductsDATE_INSERTED;
	TMemoField *QrySelProductsDESCRIPTION;
	TIntegerField *QryPaymentMethDUE_DAYS;
	TLabel *Label11;
	TLabel *lblPrice;
	TLabel *Label13;
	TLabel *lblVatValue;
	TLabel *Label12;
	TLabel *lblTotal;
	TIntegerField *DatasetInvLinesINVLINE_ID;
	TIntegerField *DatasetInvLinesINVOICE_ID;
	TIntegerField *DatasetInvLinesPRODUCT_ID;
	TIBBCDField *DatasetInvLinesPRICE_PER_ITEM;
	TIBBCDField *DatasetInvLinesPRICE;
	TIBBCDField *DatasetInvLinesPRICEWVAT;
	TIBStringField *DatasetInvLinesDESCRIPTION_SHORT;
	TIBBCDField *DatasetInvoiceDISCOUNT;
	TIBBCDField *DatasetInvoicePRICE;
	TIBBCDField *DatasetInvoicePRICEWVAT;
	TLabel *Label16;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetButton *JvDotNetButton2;
	TLabel *Label14;
	TJvDotNetEdit *editDiscPercent;
	TLabel *Label15;
	TJvDotNetEdit *editDiscount;
	TLabel *lblEurosign;
	TIBStringField *DatasetInvLinesBARCODE;
	TLabel *lblSdap;
	TIntegerField *DatasetInvoiceCONV_INVOICE_ID;
	TTimeField *DatasetInvoiceINVTIME;
	TJvTimeEdit *editTime;
	TLabel *Label17;
	TIBStringField *QryInvTypesFRM_FILENAME;
	TIBStringField *QryInvTypesEAFDSS_SCRIPT;
	TIntegerField *QryInvTypesINVCOUNT;
	TIBBCDField *DatasetInvLinesQTY;
	TJvPopupMenu *JvPopupMenu1;
	TMenuItem *MenuAddLine;
	TMenuItem *MenuDeleteLine;
	TMenuItem *MenuEditLine;
	TIntegerField *QrySelProductsMETRIC_ID;
	TIBBCDField *QrySelProductsRESERVE;
	TIBBCDField *QrySelProductsRESERVE_SECURE;
	TDateTimeField *QrySelProductsLAST_UPDATE;
	TIBBCDField *QrySelProductsVAT_VALUE;
	TIBBCDField *DatasetInvLinesVATPERCENT;
	TIBBCDField *QrySelCustomerDISCOUNT;
	TIBStringField *QrySelCustomerEMAIL;
	TJvDotNetButton *cmdNewItem;
	TJvDotNetButton *cmdDeleteItem;
	TJvDotNetButton *cmdEditItem;
	TIBQuery *QryVatCategory;
	TIntegerField *QryVatCategoryVATCAT_ID;
	TIBStringField *QryVatCategoryDESCRIPTION;
	TIBBCDField *QryVatCategoryVALUE;
	TMemoField *QryVatCategoryLONG_DESCRIPTION;
	TSmallintField *QryVatCategoryDEFAULT_CAT;
	TJvDotNetButton *cmdAccept;
	TJvDotNetButton *cmdSearch;
	TJvDotNetButton *cmdCancel;
	TIBBCDField *DatasetInvLinesPRICE_PER_ITEM_WVAT;
	TIBBCDField *DatasetInvLinesDISCOUNT;
	TSmallintField *QryInvTypesSHOW_ON_MENU;
	TIBStringField *QryInvTypesPRINTER_NAME;
	TSmallintField *QryInvTypesPRINTER_NO;
	TIntegerField *QryInvTypesDISTAIM_ID;
	TIntegerField *QryInvTypesDELIVERYMETHOD_ID;
	TIntegerField *QryInvTypesPAYMETH_ID;
	TIBStringField *DatasetInvoiceADDRESS1;
	TIBStringField *DatasetInvoiceADDRESS2;
	TIBStringField *DatasetInvoiceCITY;
	TIBStringField *DatasetInvoicePOSTCODE;
	TIBStringField *DatasetInvoiceCOUNTRY;
	TcxDBTextEdit *cxDBTextEdit1;
	TLabel *Label18;
	TIBStringField *DatasetInvLinesMETRIC_UNIT;
	TcxDBMemo *cxDBMemo1;
	TIBStringField *DatasetInvLinesNOTES;
	TIBStringField *DatasetInvoiceNOTES;
	TIBStringField *DatasetInvLinesPRODUCT_DESCR;
	TcxDBTextEdit *cxDBTextEdit2;
	TcxDBTextEdit *cxDBTextEdit3;
	TcxDBLookupComboBox *comboInvType;
	TcxDBLookupComboBox *comboDistAim;
	TcxDBLookupComboBox *comboDeliveryMethod;
	TcxDBLookupComboBox *comboPaymeth;
	TcxDBDateEdit *cxDBDateEdit1;
	TcxDBDateEdit *cxDBDateEdit2;
	TcxDBTextEdit *cxDBTextEdit4;
	TLabel *Label19;
	TLabel *Label20;
	TcxDBTextEdit *cxDBTextEdit5;
	TIntegerField *QrySelCustomerALT_CUSTID;
	TIntegerField *QrySelCustomerORDER;
	TIBStringField *QrySelCustomerCOUNTRY;
	TIntegerField *QrySelCustomerPAYMETH_ID;
	TcxTextEdit *editName;
	TcxTextEdit *editVatNo;
	TcxTextEdit *editOccupation;
	TLabel *Label21;
	TWideMemoField *DatasetInvoiceNOTES_OLD;
	TIntegerField *DatasetInvoiceCODE;
	TSmallintField *DatasetInvoiceMYDATA_SENT;
	TIBStringField *DatasetInvoiceCOMPANY_NAME;
	TIBStringField *DatasetInvoiceVAT_NO;
	TIBStringField *DatasetInvoiceVIES_VAT;
	TIBStringField *DatasetInvoiceOCCUPATION;
	TIBStringField *DatasetInvoiceEMAIL_SENT;
	TIBBCDField *DatasetInvoiceWITHHOLD_AMOUNT;
	TcxCheckBox *checkWithhold;
	TIBStringField *QrySelCustomerSECONDARY_EMAIL;
	TIBStringField *QrySelCustomerVAT_VIES;
	TIBStringField *QrySelCustomerTYPE;
	TIntegerField *QrySelCustomerWITHHOLD_TAX;
	void __fastcall FormShow(TObject *Sender);
	void __fastcall editNameKeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall editVatNo1KeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall editVatNo1KeyPress(TObject *Sender, char &Key);
	void __fastcall editNameChange(TObject *Sender);
	void __fastcall editVatNo1Change(TObject *Sender);
	void __fastcall ActiveControlChanged(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall GridInvoiceLinesKeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall DatasetInvLinesAfterPost(TDataSet *DataSet);
	void __fastcall GridInvoiceLinesDblClick(TObject *Sender);
	void __fastcall DatasetInvLinesAfterCancel(TDataSet *DataSet);
	void __fastcall GridInvoiceLinesMouseDown(TObject *Sender, TMouseButton Button,
          TShiftState Shift, int X, int Y);
	void __fastcall GridInvoiceLinesKeyPress(TObject *Sender, char &Key);
	void __fastcall DatasetInvLinesQTYChange(TField *Sender);
	void __fastcall GridInvoiceLinesColEnter(TObject *Sender);
	void __fastcall GridInvoiceLinesColExit(TObject *Sender);
	void __fastcall DatasetInvLinesDISCOUNTChange(TField *Sender);
	void __fastcall DatasetInvLinesBeforeEdit(TDataSet *DataSet);
	void __fastcall editDiscPercentKeyPress(TObject *Sender, char &Key);
	void __fastcall DatasetInvLinesAfterInsert(TDataSet *DataSet);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall DatasetInvLinesPRICE_PER_ITEMChange(TField *Sender);
	void __fastcall FormActivate(TObject *Sender);
	void __fastcall DatasetInvLinesBeforePost(TDataSet *DataSet);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall DatasetInvoiceBeforePost(TDataSet *DataSet);
	void __fastcall DatasetInvLinesAfterDelete(TDataSet *DataSet);
	void __fastcall DatasetInvLinesPRODUCT_IDChange(TField *Sender);
	void __fastcall MenuAddLineClick(TObject *Sender);
	void __fastcall MenuDeleteLineClick(TObject *Sender);
	void __fastcall MenuEditLineClick(TObject *Sender);
	void __fastcall cmdNewItemClick(TObject *Sender);
	void __fastcall cmdDeleteItemClick(TObject *Sender);
	void __fastcall cmdEditItemClick(TObject *Sender);
	void __fastcall QryVatCategoryAfterOpen(TDataSet *DataSet);
	void __fastcall GridInvoiceLinesCellClick(TColumn *Column);
	void __fastcall DatasetInvLinesAfterEdit(TDataSet *DataSet);
	void __fastcall cmdSearchClick(TObject *Sender);
	void __fastcall cmdAcceptClick(TObject *Sender);
	void __fastcall cmdCancelClick(TObject *Sender);
	void __fastcall DatasetInvLinesPRICE_PER_ITEM_WVATValidate(TField *Sender);
	void __fastcall GridInvoiceLinesEditChange(TObject *Sender);
	void __fastcall DatasetInvLinesVATPERCENTValidate(TField *Sender);
	void __fastcall cmdCalculeteDiscountClick(TObject *Sender);
	void __fastcall editDiscPercentChange(TObject *Sender);
	void __fastcall editDiscountChange(TObject *Sender);
	void __fastcall editDiscountKeyPress(TObject *Sender, char &Key);
	void __fastcall JvDBLookupCombo2Change(TObject *Sender);
	void __fastcall comboDistAimEnter(TObject *Sender);
	void __fastcall comboDeliveryMethodEnter(TObject *Sender);
	void __fastcall comboPaymethEnter(TObject *Sender);
	void __fastcall QrySelCustomerAfterOpen(TDataSet *DataSet);

private:	// User declarations
	bool gridPricesWVat;
	TDate runningDate;
	int CumInvoiceId;
	unsigned int invoice_id;
	int selRow, selCol;
	void genInvoiceId();
	void showData();
	void showLineData();
	bool someFlag;
	void  setProductId(int _prId);

	void calcPrices();
	void showSums();
	AnsiString getInvCode();
	bool checkAllFields();
	void checkReserve();
	int findCumInvoiceDate(TDate _date);
	double returnAvailQty(int SdapInvId, int productId);
	void __fastcall ActionCloseExecNew(TObject *Sender);
	void updateReserve(int productId, double qty);
	//show/hide buttons
	void showButtons();
	void hideButtons();
	//check if prices will be show with VAT or without
	void checkPricesWVat();
	double getPriceSumWVat();
	double getPriceSumWOutVat();
	ptrSetInvId ptrSetInvoiceId;
	bool notify;
public:		// User declarations
	__fastcall TFrmAddInvoice(TComponent* Owner ,AnsiString invType);
	void setCustomerId(int _id);
	void addInvLine(int _productId, AnsiString _description, double _qty, double _price, int _taxed);
	void setNotifier(ptrSetInvId _ptr);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmAddInvoice *FrmAddInvoice;
//---------------------------------------------------------------------------
#endif
